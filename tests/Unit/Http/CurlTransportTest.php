<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Http;

use Ldkafka\Ai\Exception\TransportException;
use Ldkafka\Ai\Http\CurlTransport;
use Ldkafka\Ai\Http\HttpRequest;
use Ldkafka\Ai\Internal\Json;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fsockopen;
use function is_array;
use function is_resource;
use function proc_open;
use function proc_terminate;
use function stream_socket_get_name;
use function stream_socket_server;
use function usleep;

/**
 * CurlTransport against PHP's built-in server (tests/Support/echo-server.php).
 */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../../Support/echo-server.php'];
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        self::assertIsResource($process);
        self::$server = $process;
        self::$base = 'http://127.0.0.1:' . $port;

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        self::fail('The built-in server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
        }
    }

    public function testSendsMethodHeadersAndBodyAndReadsTheAnswer(): void
    {
        $transport = new CurlTransport(10.0, 5.0);

        $response = $transport->send(new HttpRequest('POST', self::$base . '/v1/messages', ['content-type' => 'application/json', 'X-Custom' => 'abc'], '{"a":1}'));

        self::assertSame(200, $response->status);
        self::assertSame('yes', $response->header('X-TEST'));
        $echo = Json::decode($response->body);
        self::assertIsArray($echo);
        self::assertSame('POST', $echo['method']);
        self::assertSame('/v1/messages', $echo['path']);
        self::assertSame('{"a":1}', $echo['body']);
        $headers = $echo['headers'];
        self::assertTrue(is_array($headers));
        self::assertSame('abc', $headers['x-custom'] ?? null);
        self::assertArrayNotHasKey('expect', $headers);

        // The same handle serves a second request.
        $second = $transport->send(new HttpRequest('GET', self::$base . '/again'));
        self::assertSame(200, $second->status);
    }

    public function testErrorStatusIsReturnedNotThrown(): void
    {
        $response = (new CurlTransport(10.0))->send(new HttpRequest('POST', self::$base . '/status/529', [], '{}'));

        self::assertSame(529, $response->status);
    }

    public function testConnectionFailureIsNotATimeoutAndHidesTheQuery(): void
    {
        $port = self::freePort();
        try {
            (new CurlTransport(5.0, 2.0))->send(new HttpRequest('POST', 'http://127.0.0.1:' . $port . '/v1/messages?key=secret', [], '{}'));
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertFalse($e->isTimeout());
            self::assertFalse($e->isAfterSend(), 'A refused connection never sent the request.');
            self::assertStringContainsString('http://127.0.0.1:' . $port . '/v1/messages', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testReadTimeoutIsMarked(): void
    {
        try {
            (new CurlTransport(0.3, 2.0))->send(new HttpRequest('POST', self::$base . '/sleep/1500', [], '{}'));
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertTrue($e->isTimeout());
            self::assertTrue($e->isAfterSend());
        }
    }

    public function testEmptyUrlIsRejected(): void
    {
        $this->expectException(TransportException::class);
        (new CurlTransport())->send(new HttpRequest('POST', ''));
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
