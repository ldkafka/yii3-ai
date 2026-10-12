<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tool;

use Ldkafka\Ai\Exception\ToolException;
use Ldkafka\Ai\Message\Document;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Text;

/**
 * A tool the model may call.
 *
 * The getters match `YiiMcp\McpServer\Contract\McpToolInterface`, so one class can implement both;
 * execution is handle() here and execute() there, because their return shapes differ.
 */
interface ToolInterface
{
    /**
     * The tool name: `^[a-zA-Z0-9_-]{1,64}$`, the strictest rule across providers.
     */
    public function getName(): string;

    /**
     * What the tool does, for the model.
     */
    public function getDescription(): string;

    /**
     * The JSON Schema of the arguments, type object.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Run the tool. The arguments are decoded but not validated against the schema.
     *
     * @param array<string, mixed> $arguments
     * @return string|list<Text|Image|Document>
     * @throws ToolException With a message for the model; other exceptions reach only the local log.
     */
    public function handle(array $arguments): string|array;
}
