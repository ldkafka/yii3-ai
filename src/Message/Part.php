<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Message;

/**
 * One content block of a message.
 *
 * The set is closed: Text, Image, Document, ToolCall, ToolResult, Thinking and ProviderPart.
 * {@see Message::fromArray()} reads the arrays back.
 */
interface Part
{
    /**
     * The neutral storage form, with a `type` key.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
