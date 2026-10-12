<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Mcp;

use Ldkafka\Ai\Exception\McpException;
use Ldkafka\Ai\Exception\ToolException;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Mcp\McpHttpClient;
use Ldkafka\Ai\Mcp\RemoteMcpTool;
use Ldkafka\Ai\Mcp\RemoteMcpToolSource;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Provider\Anthropic\AnthropicMapper;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Tests\Support\ArrayLogger;
use Ldkafka\Ai\Tests\Support\FixtureTransport;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;

/**
 * The remote MCP client against hand-written exchanges (DESIGN 5.10): a yii3-mcp-server-shaped
 * stateless server, a session server, event streams, cursor pages, tool errors, content types,
 * refusals and protocol versions.
 */
final class McpConformanceTest extends TestCase
{
    private const URL = 'https://mcp.example.test/mcp';
    private const TOKEN = 'test-token-0000-placeholder';
    private const UA = 'watch-test/1.0';

    public function testHandshakeWithStatelessServer(): void
    {
        $transport = $this->transport('handshake_stateless');
        $logger = new ArrayLogger();
        $client = new McpHttpClient(self::URL, $transport, self::TOKEN, self::UA, logger: $logger);

        $registry = (new RemoteMcpToolSource($client, logger: $logger))->registry();

        self::assertSame('2025-06-18', $client->protocolVersion());
        self::assertNull($client->sessionId());
        self::assertSame(['prod_check', 'app_logs'], array_map(static fn ($spec): string => $spec->name, $registry->specs()));
        self::assertTrue($logger->has('warning', 'logs.read'), 'A tool name with a dot is skipped with a warning.');

        // An empty "properties" object reaches the provider as {}.
        $profile = new ModelProfile('m', 'anthropic', 'm', [], 1000, 100, 100);
        $payload = Json::encode((new AnthropicMapper())->toPayload(new Request([Message::user('x')], tools: $registry->specs()), $profile));
        self::assertStringContainsString('"name":"app_logs","description":"Application log files.","input_schema":{"type":"object","properties":{}}', $payload);

        $prodCheck = $registry->get('prod_check');
        self::assertNotNull($prodCheck);
        self::assertSame('All quiet since 06:00.', $prodCheck->handle(['since' => '06:00']));
        $appLogs = $registry->get('app_logs');
        self::assertNotNull($appLogs);
        self::assertSame("line 1\nline 2", $appLogs->handle([]));

        foreach ($transport->requests as $request) {
            self::assertSame('Bearer ' . self::TOKEN, $request->headers['authorization'] ?? null);
        }
        self::assertTrue($transport->exhausted());
    }

    public function testEventStreamAndCursorPages(): void
    {
        $transport = $this->transport('event_stream_and_cursor');
        $client = new McpHttpClient(self::URL, $transport, self::TOKEN, self::UA);

        $tools = $client->listTools();

        self::assertSame(['prod_check', 'app_logs'], array_map(static fn (array $tool): mixed => $tool['name'] ?? null, $tools));
        self::assertTrue($transport->exhausted());
    }

    public function testSessionExpiryReinitializesOnce(): void
    {
        $transport = $this->transport('session_expiry');
        $client = new McpHttpClient(self::URL, $transport, self::TOKEN, self::UA);

        $tool = new RemoteMcpTool($client, 'prod_check', '', ['type' => 'object']);
        self::assertSame('done', $tool->handle(['since' => '06:00']));
        self::assertSame('sess-2', $client->sessionId());
        self::assertSame('2025-11-25', $client->protocolVersion());

        $client->close();
        self::assertNull($client->sessionId());
        self::assertTrue($transport->exhausted());
    }

    public function testToolErrors(): void
    {
        $transport = $this->transport('tool_errors');
        $client = new McpHttpClient(self::URL, $transport, self::TOKEN, self::UA);
        $tool = new RemoteMcpTool($client, 'prod_check', '', ['type' => 'object']);

        try {
            $tool->handle(['since' => 'x']);
            self::fail('isError must throw ToolException.');
        } catch (ToolException $e) {
            self::assertSame('Query failed: table not found', $e->getMessage());
        }

        try {
            $tool->handle(['since' => 5]);
            self::fail('Invalid params must throw ToolException.');
        } catch (ToolException $e) {
            self::assertStringContainsString('since must be a string', $e->getMessage());
        }

        try {
            $tool->handle(['since' => '06:00']);
            self::fail('An internal error must throw McpException.');
        } catch (McpException $e) {
            self::assertSame(-32603, $e->rpcCode);
            self::assertNull($e->httpStatus);
        }
        self::assertTrue($transport->exhausted());
    }

    public function testContentTypes(): void
    {
        $transport = $this->transport('content_types');
        $client = new McpHttpClient(self::URL, $transport, self::TOKEN, self::UA);

        $parts = (new RemoteMcpTool($client, 'chart', '', []))->handle([]);
        self::assertIsArray($parts);
        self::assertCount(6, $parts);
        self::assertInstanceOf(Text::class, $parts[0]);
        self::assertInstanceOf(Image::class, $parts[1]);
        self::assertSame('image/png', $parts[1]->mediaType);
        self::assertEquals(new Text('[image/svg+xml image omitted]'), $parts[2]);
        self::assertEquals(new Text('[audio content omitted]'), $parts[3]);
        self::assertEquals(new Text('Report body'), $parts[4]);
        self::assertEquals(new Text('Resource: file:///big.csv'), $parts[5]);

        self::assertSame('{"bookings":12,"filters":{}}', (new RemoteMcpTool($client, 'stats', '', []))->handle([]));
        self::assertTrue($transport->exhausted());
    }

    public function testRefusalsAreNotRetried(): void
    {
        $transport = $this->transport('refused');

        foreach ([401, 403] as $index => $status) {
            $client = new McpHttpClient(self::URL, $transport, self::TOKEN, self::UA);
            try {
                $client->listTools();
                self::fail('A refusal must throw.');
            } catch (McpException $e) {
                self::assertSame($status, $e->httpStatus);
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            }
            self::assertCount($index + 1, $transport->requests, 'One request per refusal, no retry.');
        }
        self::assertTrue($transport->exhausted());
    }

    public function testUnsupportedProtocolVersions(): void
    {
        $transport = $this->transport('version_unsupported');

        try {
            (new McpHttpClient(self::URL, $transport, self::TOKEN, self::UA))->initialize();
            self::fail('2024-11-05 must be refused.');
        } catch (McpException $e) {
            self::assertStringContainsString('2024-11-05', $e->getMessage());
        }
        self::assertCount(1, $transport->requests, 'No initialized notification after a refused version.');

        try {
            (new McpHttpClient(self::URL, $transport, self::TOKEN, self::UA))->initialize();
            self::fail('A modern-only server must surface as an error.');
        } catch (McpException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame(-32022, $e->rpcCode);
        }
        self::assertSame(2, count($transport->requests));
        self::assertTrue($transport->exhausted());
    }

    private function transport(string $scenario): FixtureTransport
    {
        return FixtureTransport::fromFile(__DIR__ . '/fixtures/' . $scenario . '.json');
    }
}
