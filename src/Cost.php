<?php

declare(strict_types=1);

namespace Ldkafka\Ai;

/**
 * A USD estimate at list price. The provider's invoice is authoritative.
 */
final readonly class Cost
{
    /**
     * @param float $input Uncached input.
     * @param float $output Output, reasoning included.
     * @param float $cacheRead Cache reads.
     * @param float $cacheWrite Cache writes, all TTLs.
     */
    public function __construct(public float $input, public float $output, public float $cacheRead, public float $cacheWrite)
    {
    }

    /**
     * The total in USD.
     */
    public function total(): float
    {
        return $this->input + $this->output + $this->cacheRead + $this->cacheWrite;
    }

    /**
     * The sum of two costs.
     */
    public function add(self $other): self
    {
        return new self(
            $this->input + $other->input,
            $this->output + $other->output,
            $this->cacheRead + $other->cacheRead,
            $this->cacheWrite + $other->cacheWrite,
        );
    }
}
