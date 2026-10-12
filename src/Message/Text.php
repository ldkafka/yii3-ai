<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Message;

/**
 * Plain text.
 */
final readonly class Text implements Part
{
    /**
     * @param string $text The text.
     */
    public function __construct(public string $text)
    {
    }

    /**
     * @return array{type: 'text', text: string}
     */
    public function toArray(): array
    {
        return ['type' => 'text', 'text' => $this->text];
    }
}
