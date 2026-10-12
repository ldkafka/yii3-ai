<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tool;

use Ldkafka\Ai\Exception\ConfigurationException;

use function array_map;
use function array_values;
use function preg_match;

/**
 * The tools of a run, by name: the allow-list. Immutable.
 */
final class ToolRegistry
{
    public const NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * @param iterable<ToolInterface> $tools
     * @throws ConfigurationException On an invalid or duplicate name.
     */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->add($tool);
        }
    }

    /**
     * A copy with one more tool.
     *
     * @throws ConfigurationException On an invalid or duplicate name.
     */
    public function with(ToolInterface $tool): self
    {
        $copy = clone $this;
        $copy->add($tool);

        return $copy;
    }

    /**
     * A copy holding only the named tools.
     *
     * @throws ConfigurationException When a name is not registered.
     */
    public function only(string ...$names): self
    {
        $copy = new self();
        foreach ($names as $name) {
            if (!isset($this->tools[$name])) {
                throw new ConfigurationException('Tool "' . $name . '" is not registered.');
            }
            $copy->tools[$name] = $this->tools[$name];
        }

        return $copy;
    }

    /**
     * A tool by name, or null.
     */
    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * The specs of every tool, in registration order.
     *
     * @return list<ToolSpec>
     */
    public function specs(): array
    {
        return array_values(array_map(static fn (ToolInterface $tool): ToolSpec => ToolSpec::fromTool($tool), $this->tools));
    }

    /**
     * @throws ConfigurationException
     */
    private function add(ToolInterface $tool): void
    {
        $name = $tool->getName();
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new ConfigurationException('Invalid tool name "' . $name . '": use 1 to 64 of a-z, A-Z, 0-9, _ and -.');
        }
        if (isset($this->tools[$name])) {
            throw new ConfigurationException('Duplicate tool name "' . $name . '".');
        }
        $this->tools[$name] = $tool;
    }
}
