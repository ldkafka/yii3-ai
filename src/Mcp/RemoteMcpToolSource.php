<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Mcp;

use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Exception\McpException;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Tool\ToolRegistry;
use Psr\Log\LoggerInterface;

use function array_values;
use function in_array;
use function preg_match;

/**
 * A remote MCP server's tools, optionally limited to an allow-list of names.
 */
final class RemoteMcpToolSource
{
    /** @var list<string> */
    private array $allow;

    /**
     * @param McpHttpClient $client The server connection.
     * @param array<string> $allow Tool names to offer; [] = every tool the server lists.
     * @param LoggerInterface|null $logger Receives warnings for skipped or missing tools.
     */
    public function __construct(private McpHttpClient $client, array $allow = [], private ?LoggerInterface $logger = null)
    {
        $this->allow = array_values($allow);
    }

    /**
     * The server's tools, one `tools/list` per call.
     *
     * @return list<RemoteMcpTool>
     * @throws McpException
     */
    public function tools(): array
    {
        $tools = [];
        $found = [];
        foreach ($this->client->listTools() as $definition) {
            $name = Json::string($definition, 'name') ?? '';
            if ($this->allow !== [] && !in_array($name, $this->allow, true)) {
                continue;
            }
            if (preg_match(ToolRegistry::NAME_PATTERN, $name) !== 1) {
                $this->logger?->warning('MCP tool {tool} skipped: its name breaks the tool name rule.', ['tool' => $name]);
                continue;
            }
            $found[] = $name;
            $tools[] = RemoteMcpTool::fromDefinition($this->client, $definition);
        }
        foreach ($this->allow as $name) {
            if (!in_array($name, $found, true)) {
                $this->logger?->warning('Allow-listed MCP tool {tool} is not offered by the server.', ['tool' => $name]);
            }
        }

        return $tools;
    }

    /**
     * $base plus the server's tools.
     *
     * @throws McpException
     * @throws ConfigurationException When a tool name is already in $base.
     */
    public function registry(ToolRegistry $base = new ToolRegistry()): ToolRegistry
    {
        foreach ($this->tools() as $tool) {
            $base = $base->with($tool);
        }

        return $base;
    }
}
