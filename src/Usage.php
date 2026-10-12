<?php

declare(strict_types=1);

namespace Ldkafka\Ai;

/**
 * Token counts of one or more calls. The input counters are disjoint; output includes reasoning.
 */
final readonly class Usage
{
    /**
     * @param int $inputTokens Uncached input tokens.
     * @param int $outputTokens Output tokens, reasoning included.
     * @param int $cacheReadTokens Input tokens read from the prompt cache.
     * @param int $cacheWriteTokens Input tokens written to the prompt cache (all TTLs).
     * @param int $reasoningTokens The part of the output spent on reasoning.
     * @param array<string, int> $details Provider-specific counters, e.g. `cacheWrite1h`.
     */
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        public int $reasoningTokens = 0,
        public array $details = [],
    ) {
    }

    /**
     * The prompt size: input plus cache reads plus cache writes.
     */
    public function promptTokens(): int
    {
        return $this->inputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }

    /**
     * The sum of two usages; details are summed by key.
     */
    public function add(self $other): self
    {
        $details = $this->details;
        foreach ($other->details as $key => $value) {
            $details[$key] = ($details[$key] ?? 0) + $value;
        }

        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens,
            $this->reasoningTokens + $other->reasoningTokens,
            $details,
        );
    }
}
