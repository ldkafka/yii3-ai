<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Message;

/**
 * Model reasoning. `$opaque` holds the provider's own fields (Anthropic: `signature`, or `data` for
 * redacted thinking); it is echoed to that provider only.
 */
final readonly class Thinking implements Part
{
    /**
     * @param string $text The visible reasoning text; may be empty.
     * @param string $provider The provider that produced it.
     * @param array<string, mixed> $opaque Provider fields, echoed verbatim.
     */
    public function __construct(public string $text, public string $provider, public array $opaque = [])
    {
    }

    /**
     * @return array{type: 'thinking', text: string, provider: string, opaque: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['type' => 'thinking', 'text' => $this->text, 'provider' => $this->provider, 'opaque' => $this->opaque];
    }
}
