<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Mcp;

use Ldkafka\Ai\Client;
use Ldkafka\Ai\Exception\McpException;
use Ldkafka\Ai\Exception\TransportException;
use Ldkafka\Ai\Http\HttpResponse;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Mcp\McpHttpClient;
use Ldkafka\Ai\Tests\Support\ArrayLogger;
use Ldkafka\Ai\Tests\Support\QueueTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpHttpClientTest extends TestCase
{
    private const INIT = '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{}},"serverInfo":{"name":"s","version":"1"}}}';

    public function testNoTokenMeansNoAuthorizationHeaderAndTheDefaultUserAgent(): void
    {
        $transport = new QueueTransport([self::json(self::INIT), new HttpResponse(202, [], '')]);
        $logger = new ArrayLogger();

        $result = (new McpHttpClient('https://mcp.example.test/mcp', $transport, logger: $logger))->initialize();

        self::assertSame('2025-06-18', $result['protocolVersion']);
        self::assertArrayNotHasKey('authorization', $transport->requests[0]->headers);
        self::assertSame('ldkafka-yii3-ai/' . Client::VERSION, $transport->requests[0]->headers['user-agent']);
        self::assertCount(2, $logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
        self::assertStringContainsString('MCP initialize: HTTP 200', $logger->records[0]['message']);
    }

    public function testInitializeRunsOnce(): void
    {
        $transport = new QueueTransport([
            self::json(self::INIT),
            new HttpResponse(202, [], ''),
            self::json('{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}'),
            self::json('{"jsonrpc":"2.0","id":3,"result":{"tools":[]}}'),
        ]);
        $client = new McpHttpClient('https://mcp.example.test/mcp', $transport);

        $client->listTools();
        $client->listTools();
        $client->initialize();

        self::assertCount(4, $transport->requests);
    }

    public function testBatchAnswerAndEmptyResult(): void
    {
        $transport = new QueueTransport([
            self::json(self::INIT),
            new HttpResponse(202, [], ''),
            self::json('[{"jsonrpc":"2.0","method":"notifications/message","params":{}},{"jsonrpc":"2.0","id":2,"result":{}}]'),
        ]);

        self::assertSame([], (new McpHttpClient('https://mcp.example.test/mcp', $transport))->callTool('t', []));
        $body = Json::decodeObjects($transport->requests[2]->body);
        self::assertSame('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"t","arguments":{}}}', Json::encode($body));
    }

    public function testRepeatedCursorStops(): void
    {
        $transport = new QueueTransport([
            self::json(self::INIT),
            new HttpResponse(202, [], ''),
            self::json('{"jsonrpc":"2.0","id":2,"result":{"tools":[],"nextCursor":"a"}}'),
            self::json('{"jsonrpc":"2.0","id":3,"result":{"tools":[],"nextCursor":"a"}}'),
        ]);

        $this->expectException(McpException::class);
        (new McpHttpClient('https://mcp.example.test/mcp', $transport))->listTools();
    }

    /**
     * @return iterable<string, array{HttpResponse}>
     */
    public static function badAnswers(): iterable
    {
        yield 'not json' => [new HttpResponse(200, ['content-type' => 'application/json'], '<html>')];
        yield 'other id' => [self::json('{"jsonrpc":"2.0","id":99,"result":{}}')];
        yield 'no result' => [self::json('{"jsonrpc":"2.0","id":1,"result":"text"}')];
        yield 'empty stream' => [new HttpResponse(200, ['content-type' => 'text/event-stream'], "data: \n\n")];
        yield 'no version' => [self::json('{"jsonrpc":"2.0","id":1,"result":{}}')];
    }

    #[DataProvider('badAnswers')]
    public function testBadAnswers(HttpResponse $answer): void
    {
        $this->expectException(McpException::class);
        (new McpHttpClient('https://mcp.example.test/mcp', new QueueTransport([$answer])))->initialize();
    }

    public function testTransportFailureBecomesMcpException(): void
    {
        $cause = new TransportException('HTTP request to https://mcp.example.test/mcp failed: timed out (curl 28)', true);

        try {
            (new McpHttpClient('https://mcp.example.test/mcp', new QueueTransport([$cause]), 'tok-secret'))->initialize();
            self::fail('Expected McpException');
        } catch (McpException $e) {
            self::assertSame($cause, $e->getPrevious());
            self::assertNull($e->httpStatus);
            self::assertStringNotContainsString('tok-secret', $e->getMessage());
        }
    }

    public function testServerErrorIsNotRetried(): void
    {
        $transport = new QueueTransport([
            self::json(self::INIT),
            new HttpResponse(202, [], ''),
            new HttpResponse(503, [], 'Service Unavailable'),
        ]);

        try {
            (new McpHttpClient('https://mcp.example.test/mcp', $transport))->callTool('t', ['a' => 1]);
            self::fail('Expected McpException');
        } catch (McpException $e) {
            self::assertSame(503, $e->httpStatus);
            self::assertSame('MCP server answered HTTP 503 to tools/call', $e->getMessage());
        }
        self::assertCount(3, $transport->requests);
    }

    public function testCloseWithoutSessionSendsNothing(): void
    {
        $transport = new QueueTransport();

        (new McpHttpClient('https://mcp.example.test/mcp', $transport))->close();

        self::assertSame([], $transport->requests);
    }

    private static function json(string $body): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => 'application/json'], $body);
    }

    public function testCyclingCursorStops(): void
    {
        $transport = new QueueTransport([
            self::json(self::INIT),
            new HttpResponse(202, [], ''),
            self::json('{"jsonrpc":"2.0","id":2,"result":{"tools":[],"nextCursor":"A"}}'),
            self::json('{"jsonrpc":"2.0","id":3,"result":{"tools":[],"nextCursor":"B"}}'),
            self::json('{"jsonrpc":"2.0","id":4,"result":{"tools":[],"nextCursor":"A"}}'),
        ]);

        try {
            (new McpHttpClient('https://mcp.example.test/mcp', $transport))->listTools();
            self::fail('Expected McpException');
        } catch (McpException $e) {
            self::assertStringContainsString('repeated', $e->getMessage());
        }
        self::assertCount(5, $transport->requests);
    }

    public function testPageCap(): void
    {
        $answers = [self::json(self::INIT), new HttpResponse(202, [], '')];
        for ($page = 1; $page <= McpHttpClient::MAX_PAGES + 1; $page++) {
            $answers[] = self::json('{"jsonrpc":"2.0","id":' . ($page + 1) . ',"result":{"tools":[],"nextCursor":"c' . $page . '"}}');
        }
        $transport = new QueueTransport($answers);

        try {
            (new McpHttpClient('https://mcp.example.test/mcp', $transport))->listTools();
            self::fail('Expected McpException');
        } catch (McpException $e) {
            self::assertStringContainsString((string) McpHttpClient::MAX_PAGES, $e->getMessage());
        }
        self::assertCount(McpHttpClient::MAX_PAGES + 2, $transport->requests);
    }

    public function testArgumentsThatCannotBeEncodedRaiseMcpException(): void
    {
        $transport = new QueueTransport([self::json(self::INIT), new HttpResponse(202, [], '')]);

        $this->expectException(McpException::class);
        (new McpHttpClient('https://mcp.example.test/mcp', $transport))->callTool('t', ['x' => INF]);
    }
}
