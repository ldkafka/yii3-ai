<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Message;

use InvalidArgumentException;

use function array_map;
use function array_values;

/**
 * The answer to one tool call. The tool name is carried because some providers need it.
 */
final readonly class ToolResult implements Part
{
    /** @var list<Text|Image|Document> */
    public array $content;

    /**
     * @param string $callId The id of the ToolCall answered.
     * @param string $name The tool name.
     * @param array<Text|Image|Document> $content The result.
     * @param bool $isError Whether the call failed.
     * @throws InvalidArgumentException When the content holds another part type.
     */
    public function __construct(public string $callId, public string $name, array $content, public bool $isError = false)
    {
        foreach ($content as $part) {
            if (!$part instanceof Text && !$part instanceof Image && !$part instanceof Document) {
                throw new InvalidArgumentException('A ToolResult holds only Text, Image or Document parts.');
            }
        }
        $this->content = array_values($content);
    }

    /**
     * A result with one Text part.
     */
    public static function text(string $callId, string $name, string $text, bool $isError = false): self
    {
        return new self($callId, $name, [new Text($text)], $isError);
    }

    /**
     * @return array{type: 'tool_result', callId: string, name: string, content: list<array<string, mixed>>, isError: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => 'tool_result',
            'callId' => $this->callId,
            'name' => $this->name,
            'content' => array_map(static fn (Part $part): array => $part->toArray(), $this->content),
            'isError' => $this->isError,
        ];
    }
}
