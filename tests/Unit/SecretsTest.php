<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit;

use Ldkafka\Ai\Exception\McpException;
use Ldkafka\Ai\Exception\TransportException;
use Ldkafka\Ai\Http\CurlTransport;
use Ldkafka\Ai\Http\HttpRequest;
use Ldkafka\Ai\Mcp\McpHttpClient;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Provider\Anthropic\AnthropicProvider;
use Ldkafka\Ai\Request;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use Throwable;

use function fclose;
use function ini_set;
use function print_r;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function var_export;

/**
 * Keys and tokens stay out of stack traces and debug dumps.
 */
final class SecretsTest extends TestCase
{
    private const KEY = 'sk-secret-key-0123456789';
    private const TOKEN = 'mcp-secret-token-0123456789';

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        // Make sure traces carry arguments, as on a development box.
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        if ($this->ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }
    }

    public function testProviderTransportFailureTraceHoldsNoKey(): void
    {
        $provider = new AnthropicProvider(self::KEY, new CurlTransport(2.0, 1.0), 'http://127.0.0.1:' . self::closedPort());

        try {
            $provider->send(new Request([Message::user('x')]), new ModelProfile('m', 'anthropic', 'm', [], 100, 10, 10));
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            $this->assertNoSecret($e, self::KEY);
            $frame = $e->getTrace()[0];
            self::assertSame('send', $frame['function']);
            self::assertInstanceOf(SensitiveParameterValue::class, $frame['args'][0] ?? null);
        }
    }

    public function testMcpTransportFailureTraceHoldsNoToken(): void
    {
        $client = new McpHttpClient('http://127.0.0.1:' . self::closedPort() . '/mcp', new CurlTransport(2.0, 1.0), self::TOKEN);

        try {
            $client->initialize();
            self::fail('Expected McpException');
        } catch (McpException $e) {
            $this->assertNoSecret($e, self::TOKEN);
            $previous = $e->getPrevious();
            self::assertInstanceOf(TransportException::class, $previous);
            $this->assertNoSecret($previous, self::TOKEN);
        }
    }

    public function testDumpsAreRedacted(): void
    {
        $provider = new AnthropicProvider(self::KEY, new CurlTransport(), workspaceId: 'wrkspc_1');
        $mcp = new McpHttpClient('https://mcp.example.test/mcp', new CurlTransport(), self::TOKEN);
        $request = new HttpRequest('POST', 'https://api.example.test', ['Authorization' => 'Bearer ' . self::KEY, 'x-api-key' => self::KEY, 'accept' => 'application/json']);

        foreach ([$provider, $mcp, $request] as $object) {
            $dump = print_r($object, true);
            self::assertStringNotContainsString(self::KEY, $dump);
            self::assertStringNotContainsString(self::TOKEN, $dump);
            self::assertStringContainsString('[redacted]', $dump);
            self::assertStringNotContainsString(self::KEY, var_export($object->__debugInfo(), true));
        }
        self::assertStringContainsString('application/json', print_r($request, true));
        self::assertStringContainsString('wrkspc_1', print_r($provider, true));
    }

    private function assertNoSecret(Throwable $e, string $secret): void
    {
        self::assertStringNotContainsString($secret, print_r($e->getTrace(), true));
        self::assertStringNotContainsString($secret, $e->getTraceAsString());
        self::assertStringNotContainsString($secret, $e->getMessage());
    }

    private static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
