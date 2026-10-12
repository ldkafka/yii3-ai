<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Mcp;

use InvalidArgumentException;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Http\HttpResponse;
use Ldkafka\Ai\Mcp\McpHttpClient;
use Ldkafka\Ai\Mcp\RemoteMcpTool;
use Ldkafka\Ai\Mcp\RemoteMcpToolSource;
use Ldkafka\Ai\Tests\Support\ArrayLogger;
use Ldkafka\Ai\Tests\Support\CallbackTool;
use Ldkafka\Ai\Tests\Support\QueueTransport;
use Ldkafka\Ai\Tool\ToolRegistry;
use Ldkafka\Ai\Exception\ToolException;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Provider\Anthropic\AnthropicMapper;
use Ldkafka\Ai\Request;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * RemoteMcpToolSource and RemoteMcpTool::fromDefinition().
 */
final class RemoteMcpToolSourceTest extends TestCase
{
    public function testAllowListAndMissingNames(): void
    {
        $logger = new ArrayLogger();
        $source = new RemoteMcpToolSource($this->client(), ['b', 'zzz'], $logger);

        $tools = $source->tools();

        self::assertSame(['b'], array_map(static fn (RemoteMcpTool $t): string => $t->getName(), $tools));
        self::assertTrue($logger->has('warning', 'Allow-listed MCP tool zzz is not offered by the server.'));
    }

    public function testRegistryAddsToTheBase(): void
    {
        $base = new ToolRegistry([new CallbackTool('local', static fn (array $a): string => '')]);

        $registry = (new RemoteMcpToolSource($this->client()))->registry($base);

        self::assertNotNull($registry->get('local'));
        self::assertNotNull($registry->get('a'));
        self::assertNotNull($registry->get('b'));
    }

    public function testNameCollisionWithTheBase(): void
    {
        $this->expectException(ConfigurationException::class);
        (new RemoteMcpToolSource($this->client()))->registry(new ToolRegistry([new CallbackTool('a', static fn (array $x): string => '')]));
    }

    public function testFromDefinitionDefaults(): void
    {
        $client = new McpHttpClient('https://mcp.example.test/mcp', new QueueTransport());

        $tool = RemoteMcpTool::fromDefinition($client, ['name' => 'n', 'title' => 'Title only']);

        self::assertSame('Title only', $tool->getDescription());
        self::assertEquals(['type' => 'object', 'properties' => new stdClass()], $tool->getInputSchema());
    }

    public function testFromDefinitionNeedsAName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RemoteMcpTool::fromDefinition(new McpHttpClient('https://mcp.example.test/mcp', new QueueTransport()), ['description' => 'x']);
    }

    private function client(): McpHttpClient
    {
        return new McpHttpClient('https://mcp.example.test/mcp', new QueueTransport([
            new HttpResponse(200, ['content-type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-03-26","capabilities":{}}}'),
            new HttpResponse(202, [], ''),
            new HttpResponse(200, ['content-type' => 'application/json'], '{"jsonrpc":"2.0","id":2,"result":{"tools":[{"name":"a","description":"A"},{"name":"b","description":"B","inputSchema":{"type":"object","properties":{}}}]}}'),
        ]));
    }

    public function testNumericPropertyNamesStayAnObject(): void
    {
        $client = new McpHttpClient('https://mcp.example.test/mcp', new QueueTransport([
            new HttpResponse(200, ['content-type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{}}}'),
            new HttpResponse(202, [], ''),
            new HttpResponse(200, ['content-type' => 'application/json'], '{"jsonrpc":"2.0","id":2,"result":{"tools":[{"name":"pick","description":"P","inputSchema":{"type":"object","properties":{"0":{"type":"string"},"1":{"type":"integer"}}}}]}}'),
        ]));
        $registry = (new RemoteMcpToolSource($client))->registry();
        $profile = new ModelProfile('m', 'anthropic', 'm', [], 1000, 100, 100);

        $payload = Json::encode((new AnthropicMapper())->toPayload(new Request([Message::user('x')], tools: $registry->specs()), $profile));

        self::assertStringContainsString('"input_schema":{"type":"object","properties":{"0":{"type":"string"},"1":{"type":"integer"}}}', $payload);
    }

    public function testInvalidImageDataBecomesAToolError(): void
    {
        $client = new McpHttpClient('https://mcp.example.test/mcp', new QueueTransport([
            new HttpResponse(200, ['content-type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{}}}'),
            new HttpResponse(202, [], ''),
            new HttpResponse(200, ['content-type' => 'application/json'], '{"jsonrpc":"2.0","id":2,"result":{"content":[{"type":"image","data":"not base64 !!","mimeType":"image/png"}]}}'),
        ]));

        $this->expectException(ToolException::class);
        (new RemoteMcpTool($client, 'chart', '', []))->handle([]);
    }
}
