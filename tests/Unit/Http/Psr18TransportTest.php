<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Http;

use Ldkafka\Ai\Exception\TransportException;
use Ldkafka\Ai\Http\HttpRequest;
use Ldkafka\Ai\Http\Psr18Transport;
use Ldkafka\Ai\Tests\Support\RecordingPsr18Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class Psr18TransportTest extends TestCase
{
    public function testSendsThroughTheClient(): void
    {
        $client = $this->client(new Response(201, ['Request-Id' => 'req_1', 'X-Multi' => ['a', 'b']], '{"ok":true}'));
        $factory = new Psr17Factory();

        $response = (new Psr18Transport($client, $factory, $factory))->send(
            new HttpRequest('POST', 'https://api.example.com/v1/messages', ['content-type' => 'application/json'], '{"a":1}'),
        );

        self::assertSame(201, $response->status);
        self::assertSame('req_1', $response->header('request-id'));
        self::assertSame('a, b', $response->header('x-multi'));
        self::assertSame('{"ok":true}', $response->body);
        self::assertNotNull($client->sent);
        self::assertSame('POST', $client->sent->getMethod());
        self::assertSame('application/json', $client->sent->getHeaderLine('content-type'));
        self::assertSame('{"a":1}', (string) $client->sent->getBody());
    }

    public function testClientExceptionsBecomeTransportExceptions(): void
    {
        $error = new class ('connection refused') extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return (new Psr17Factory())->createRequest('GET', 'https://api.example.com');
            }
        };
        $factory = new Psr17Factory();

        try {
            (new Psr18Transport($this->client($error), $factory, $factory))->send(new HttpRequest('POST', 'https://api.example.com/v1/messages?k=secret'));
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertFalse($e->isTimeout());
            self::assertSame('HTTP request to https://api.example.com/v1/messages failed: connection refused', $e->getMessage());
            self::assertSame($error, $e->getPrevious());
        }
    }

    private function client(ResponseInterface|NetworkExceptionInterface $answer): RecordingPsr18Client
    {
        return new RecordingPsr18Client($answer);
    }
}
