<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Mcp;

use JsonException;
use Ldkafka\Ai\Client;
use Ldkafka\Ai\Exception\McpException;
use Ldkafka\Ai\Exception\TransportException;
use Ldkafka\Ai\Http\HttpRequest;
use Ldkafka\Ai\Http\HttpResponse;
use Ldkafka\Ai\Http\TransportInterface;
use Ldkafka\Ai\Internal\Json;
use Psr\Log\LoggerInterface;
use stdClass;

use function array_is_list;
use function explode;
use function function_exists;
use function hrtime;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function mb_substr;
use function preg_split;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * A client for a remote MCP server: JSON-RPC 2.0 over the Streamable HTTP transport, one POST
 * per message to one URL.
 *
 * Speaks the handshake-based protocol revisions: `initialize` runs once, lazily, before the first
 * call, followed by `notifications/initialized`. Keeps the `Mcp-Session-Id` the server issues and
 * sends the negotiated `MCP-Protocol-Version` on every later request. Reads JSON and
 * `text/event-stream` answers. The only retry is the one the specification requires: a 404 to a
 * request that carried a session id starts a new session and resends once. Nothing else is
 * retried, so a refused client never hammers a server (or an nginx that bans on repeated 403s).
 */
final class McpHttpClient
{
    /** The revision proposed in initialize. */
    public const PROTOCOL_VERSION = '2025-11-25';

    /** Revisions accepted in the server's answer, newest first. */
    public const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /** JSON-RPC "Invalid params". */
    public const INVALID_PARAMS = -32602;

    /** The most tools/list pages one listTools() call follows. */
    public const MAX_PAGES = 100;

    private const SNIPPET_CHARS = 300;

    /** @var array<string, mixed>|null */
    private ?array $initializeResult = null;

    private ?string $protocolVersion = null;

    private ?string $sessionId = null;

    private int $nextId = 1;

    /**
     * @param string $url The MCP endpoint, e.g. https://example.com/mcp.
     * @param TransportInterface $transport Sends the HTTP requests (set its timeout for tool calls).
     * @param string $token A bearer token; '' sends no Authorization header.
     * @param string $userAgent Sent on every request. Set your own: some servers ban library defaults.
     * @param string $clientName The clientInfo name sent in initialize.
     * @param LoggerInterface|null $logger Receives one debug line per HTTP exchange.
     */
    public function __construct(
        private string $url,
        private TransportInterface $transport,
        #[\SensitiveParameter] private string $token = '',
        private string $userAgent = 'ldkafka-yii3-ai/' . Client::VERSION,
        private string $clientName = 'ldkafka-yii3-ai',
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Run the handshake once and return the server's InitializeResult.
     *
     * @return array<string, mixed>
     * @throws McpException When the server refuses, fails or answers an unsupported protocol version.
     */
    public function initialize(): array
    {
        if ($this->initializeResult !== null) {
            return $this->initializeResult;
        }

        $result = $this->rpc('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => $this->clientName, 'version' => Client::VERSION],
        ]);
        $version = Json::string($result, 'protocolVersion');
        if ($version === null || !in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw new McpException(sprintf(
                'MCP server answered protocol version "%s"; this client speaks %s.',
                (string) $version,
                implode(', ', self::SUPPORTED_VERSIONS),
            ));
        }
        $this->protocolVersion = $version;
        $this->notify('notifications/initialized');

        return $this->initializeResult = $result;
    }

    /**
     * Every tool the server lists, following `nextCursor` across pages.
     *
     * @return list<array<string, mixed>> The tool definitions (name, description, inputSchema, ...).
     * @throws McpException
     */
    public function listTools(): array
    {
        $tools = [];
        $cursor = null;
        $seen = [];
        $pages = 0;
        do {
            if (++$pages > self::MAX_PAGES) {
                throw new McpException('MCP server sent more than ' . self::MAX_PAGES . ' tools/list pages.');
            }
            $result = $this->call('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            foreach (Json::array($result, 'tools') as $tool) {
                if (is_array($tool)) {
                    $tools[] = self::stringKeys($tool);
                }
            }
            $next = Json::string($result, 'nextCursor');
            if ($next !== null && isset($seen[$next])) {
                throw new McpException('MCP server repeated a tools/list cursor.');
            }
            if ($next !== null) {
                $seen[$next] = true;
            }
            $cursor = $next;
        } while ($cursor !== null && $cursor !== '');

        return $tools;
    }

    /**
     * Call one tool and return the CallToolResult (content, isError, structuredContent).
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws McpException
     */
    public function callTool(string $name, array $arguments): array
    {
        return $this->call('tools/call', ['name' => $name, 'arguments' => $arguments === [] ? new stdClass() : $arguments]);
    }

    /**
     * The negotiated protocol version; null before initialize.
     */
    public function protocolVersion(): ?string
    {
        return $this->protocolVersion;
    }

    /**
     * The session id the server issued; null when it issued none.
     */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * End the session: a DELETE when the server issued a session id. Any answer is accepted.
     */
    public function close(): void
    {
        if ($this->sessionId !== null) {
            try {
                $this->transport->send(new HttpRequest('DELETE', $this->url, $this->headers(false)));
            } catch (TransportException $e) {
                $this->logger?->debug('MCP session delete failed: {error}', ['error' => $e->getMessage()]);
            }
        }
        $this->reset();
    }

    /**
     * A request after the handshake, with the one session-expiry retry.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws McpException
     */
    private function call(string $method, array $params): array
    {
        $this->initialize();
        $hadSession = $this->sessionId !== null;
        try {
            return $this->rpc($method, $params);
        } catch (McpException $e) {
            if ($e->httpStatus !== 404 || !$hadSession) {
                throw $e;
            }
            $this->logger?->info('MCP session expired; starting a new one.', ['method' => $method]);
            $this->reset();
            $this->initialize();

            return $this->rpc($method, $params);
        }
    }

    /**
     * One JSON-RPC request and its result.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws McpException
     */
    private function rpc(string $method, array $params): array
    {
        $id = $this->nextId++;
        $response = $this->post($method, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? new stdClass() : $params,
        ]);
        $message = $this->responseFor($id, $response, $method);

        $error = $message['error'] ?? null;
        if (is_array($error)) {
            $code = $error['code'] ?? null;
            throw new McpException(
                sprintf('MCP %s failed: %s (JSON-RPC %s)', $method, self::snippet(Json::string($error, 'message') ?? ''), is_int($code) ? (string) $code : '?'),
                null,
                is_int($code) ? $code : null,
            );
        }
        $result = $message['result'] ?? null;
        if ($result instanceof stdClass) {
            return [];
        }
        if (!is_array($result)) {
            throw new McpException('MCP server answered ' . $method . ' without a result.');
        }

        return self::stringKeys($result);
    }

    /**
     * Send a notification; the server answers 202.
     *
     * @throws McpException
     */
    private function notify(string $method): void
    {
        $this->post($method, ['jsonrpc' => '2.0', 'method' => $method]);
    }

    /**
     * POST one message; non-2xx answers throw.
     *
     * @param array<string, mixed> $message
     * @throws McpException
     */
    private function post(string $method, array $message): HttpResponse
    {
        try {
            $body = Json::encode($message);
        } catch (JsonException $e) {
            throw new McpException('MCP ' . $method . ' request cannot be encoded as JSON: ' . $e->getMessage(), null, null, $e);
        }
        $started = hrtime(true);
        try {
            $response = $this->transport->send(new HttpRequest('POST', $this->url, $this->headers($method === 'initialize'), $body));
        } catch (TransportException $e) {
            throw new McpException('MCP ' . $method . ' request failed: ' . $e->getMessage(), null, null, $e);
        }
        $this->logger?->debug('MCP {method}: HTTP {status} in {ms} ms', [
            'method' => $method,
            'status' => $response->status,
            'ms' => intdiv((int) (hrtime(true) - $started), 1_000_000),
        ]);

        if ($response->status < 200 || $response->status >= 300) {
            $detail = '';
            $code = null;
            try {
                $decoded = Json::decode($response->body);
                $error = is_array($decoded) ? Json::array($decoded, 'error') : [];
                $text = Json::string($error, 'message');
                if ($text !== null) {
                    $detail = ': ' . self::snippet($text);
                }
                $code = is_int($error['code'] ?? null) ? $error['code'] : null;
            } catch (JsonException) {
            }

            throw new McpException(sprintf('MCP server answered HTTP %d to %s%s', $response->status, $method, $detail), $response->status, $code);
        }

        if ($method === 'initialize') {
            $session = $response->header('mcp-session-id');
            $this->sessionId = $session !== null && $session !== '' ? $session : null;
        }

        return $response;
    }

    /**
     * Headers for a request.
     *
     * @return array<string, string>
     */
    private function headers(bool $initializing): array
    {
        $headers = [
            'content-type' => 'application/json',
            'accept' => 'application/json, text/event-stream',
            'user-agent' => $this->userAgent,
        ];
        if ($this->token !== '') {
            $headers['authorization'] = 'Bearer ' . $this->token;
        }
        if ($this->sessionId !== null) {
            $headers['mcp-session-id'] = $this->sessionId;
        }
        if (!$initializing && $this->protocolVersion !== null) {
            $headers['mcp-protocol-version'] = $this->protocolVersion;
        }

        return $headers;
    }

    /**
     * The JSON-RPC response with the given id, from a JSON or an event-stream body.
     *
     * @return array<mixed>
     * @throws McpException
     */
    private function responseFor(int $id, HttpResponse $response, string $method): array
    {
        $contentType = strtolower((string) $response->header('content-type'));
        if (str_starts_with($contentType, 'text/event-stream')) {
            foreach (self::eventData($response->body) as $data) {
                try {
                    $found = self::find(Json::decodeKeepEmptyObjects($data), $id);
                } catch (JsonException) {
                    continue;
                }
                if ($found !== null) {
                    return $found;
                }
            }

            throw new McpException('MCP server sent no response to ' . $method . ' on its event stream.', $response->status);
        }

        try {
            $found = self::find(Json::decodeKeepEmptyObjects($response->body), $id);
        } catch (JsonException $e) {
            throw new McpException('MCP server answered ' . $method . ' with a body that is not JSON.', $response->status, null, $e);
        }
        if ($found === null) {
            throw new McpException('MCP server answered ' . $method . ' without its JSON-RPC response.', $response->status);
        }

        return $found;
    }

    /**
     * The response with the id in one message or a batch.
     *
     * @return array<mixed>|null
     */
    private static function find(mixed $decoded, int $id): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }
        if (array_is_list($decoded)) {
            foreach ($decoded as $message) {
                $found = self::find($message, $id);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }
        $messageId = $decoded['id'] ?? null;
        $sameId = $messageId === $id || (is_string($messageId) && $messageId === (string) $id);
        if ($sameId && !isset($decoded['method']) && (isset($decoded['result']) || isset($decoded['error']))) {
            return $decoded;
        }

        return null;
    }

    /**
     * The data of each server-sent event: `data:` lines joined by newlines.
     *
     * @return list<string>
     */
    private static function eventData(string $body): array
    {
        $out = [];
        $events = preg_split('/\n\n+/', str_replace(["\r\n", "\r"], "\n", $body));
        foreach ($events === false ? [] : $events as $event) {
            $lines = [];
            foreach (explode("\n", $event) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $value = substr($line, 5);
                    $lines[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
                }
            }
            $data = implode("\n", $lines);
            if (trim($data) !== '') {
                $out[] = $data;
            }
        }

        return $out;
    }

    /**
     * The fields for var_dump() and print_r(), without the token or the session id.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'url' => $this->url,
            'token' => $this->token === '' ? '' : '[redacted]',
            'userAgent' => $this->userAgent,
            'protocolVersion' => $this->protocolVersion,
            'sessionId' => $this->sessionId === null ? null : '[redacted]',
        ];
    }

    /**
     * Forget the handshake and the session.
     */
    private function reset(): void
    {
        $this->initializeResult = null;
        $this->protocolVersion = null;
        $this->sessionId = null;
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * The first 300 characters of a server message.
     */
    private static function snippet(string $text): string
    {
        if (strlen($text) <= self::SNIPPET_CHARS) {
            return $text;
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, self::SNIPPET_CHARS) : substr($text, 0, self::SNIPPET_CHARS);
    }
}
