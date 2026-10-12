<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tool;

/**
 * A tool's definition as sent to the model.
 */
final readonly class ToolSpec
{
    /**
     * @param string $name The tool name.
     * @param string $description What it does.
     * @param array<string, mixed> $inputSchema The JSON Schema of the arguments.
     */
    public function __construct(public string $name, public string $description, public array $inputSchema)
    {
    }

    /**
     * The spec of a tool.
     */
    public static function fromTool(ToolInterface $tool): self
    {
        return new self($tool->getName(), $tool->getDescription(), $tool->getInputSchema());
    }
}
