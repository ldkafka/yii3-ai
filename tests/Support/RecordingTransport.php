<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Support;

use JsonException;
use Ldkafka\Ai\Http\HttpRequest;
use Ldkafka\Ai\Http\HttpResponse;
use Ldkafka\Ai\Http\TransportInterface;
use Ldkafka\Ai\Internal\Json;
use RuntimeException;

use function file_put_contents;
use function gmdate;
use function in_array;
use function json_encode;
use function parse_url;
use function strtolower;

/**
 * Wraps a real transport for live runs: keeps every exchange and, on save(), writes them as a
 * fixture. Only allow-listed headers are written; never authorization or x-api-key.
 */
final class RecordingTransport implements TransportInterface
{
    public const REQUEST_HEADERS = ['anthropic-version', 'anthropic-beta', 'content-type', 'accept'];
    public const RESPONSE_HEADERS = ['request-id', 'retry-after', 'content-type'];

    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<array{request: HttpRequest, response: HttpResponse}> */
    private array $exchanges = [];

    /**
     * @param TransportInterface $inner The real transport.
     */
    public function __construct(private TransportInterface $inner)
    {
    }

    /**
     * Send through the real transport and keep the exchange.
     */
    public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $response = $this->inner->send($request);
        $this->exchanges[] = ['request' => $request, 'response' => $response];

        return $response;
    }

    /**
     * Write the exchanges as a fixture file.
     *
     * @param array<string, mixed> $meta scenario, provider, model and any flags to keep.
     */
    public function save(string $path, array $meta): void
    {
        $exchanges = [];
        foreach ($this->exchanges as $exchange) {
            $request = $exchange['request'];
            $response = $exchange['response'];
            $exchanges[] = [
                'request' => [
                    'method' => $request->method,
                    'path' => (string) parse_url($request->url, PHP_URL_PATH),
                    'headers' => self::allowed($request->headers, self::REQUEST_HEADERS),
                    'body' => self::body($request->body),
                ],
                'response' => [
                    'status' => $response->status,
                    'headers' => self::allowed($response->headers, self::RESPONSE_HEADERS),
                ] + self::responseBody($response->body),
            ];
        }

        $fixture = $meta;
        $fixture['recordedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $fixture['synthetic'] = false;
        $fixture['exchanges'] = $exchanges;
        $json = json_encode($fixture, Json::ENCODE_FLAGS | JSON_PRETTY_PRINT);
        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Cannot write fixture ' . $path);
        }
    }

    /**
     * @param array<string, string> $headers
     * @param list<string> $allowed
     * @return array<string, string>|\stdClass
     */
    private static function allowed(array $headers, array $allowed): array|\stdClass
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $name = strtolower($name);
            if (in_array($name, $allowed, true)) {
                $out[$name] = $value;
            }
        }

        return $out === [] ? new \stdClass() : $out;
    }

    /**
     * A request body as JSON data, or the text when it is not JSON.
     */
    private static function body(string $body): mixed
    {
        try {
            return Json::decodeKeepEmptyObjects($body);
        } catch (JsonException) {
            return $body;
        }
    }

    /**
     * @return array{body: mixed}|array{bodyText: string}
     */
    private static function responseBody(string $body): array
    {
        try {
            $decoded = Json::decodeKeepEmptyObjects($body);
        } catch (JsonException) {
            return ['bodyText' => $body];
        }

        return ['body' => $decoded];
    }
}
