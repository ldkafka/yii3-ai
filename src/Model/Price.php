<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Model;

use Ldkafka\Ai\Exception\ConfigurationException;

use function is_array;
use function is_float;
use function is_int;
use function krsort;

/**
 * List prices in USD per million tokens. `$above` holds higher tiers used once the prompt is
 * larger than the key (in tokens).
 */
final readonly class Price
{
    /** @var array<int, Price> Tiers by prompt-size threshold, highest first. */
    public array $above;

    /**
     * @param float $input Uncached input.
     * @param float $output Output.
     * @param float $cacheRead Cache reads.
     * @param float $cacheWrite Cache writes with the default (5 minute) TTL.
     * @param float|null $cacheWrite1h Cache writes with the 1 hour TTL; null = $cacheWrite.
     * @param array<int, Price> $above Tiers by prompt-size threshold.
     */
    public function __construct(
        public float $input,
        public float $output,
        public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0,
        public ?float $cacheWrite1h = null,
        array $above = [],
    ) {
        krsort($above);
        $this->above = $above;
    }

    /**
     * Build a price from a params array (`input`, `output`, `cacheRead`, `cacheWrite`, `cacheWrite1h`, `above`).
     *
     * @param array<mixed> $price
     * @throws ConfigurationException When a value is missing or not a number.
     */
    public static function fromConfig(array $price): self
    {
        $above = [];
        foreach (is_array($price['above'] ?? null) ? $price['above'] : [] as $threshold => $tier) {
            if (!is_int($threshold) || !is_array($tier)) {
                throw new ConfigurationException('A price tier needs an integer token threshold and a price array.');
            }
            $above[$threshold] = self::fromConfig($tier);
        }

        return new self(
            self::number($price, 'input', true) ?? 0.0,
            self::number($price, 'output', true) ?? 0.0,
            self::number($price, 'cacheRead', false) ?? 0.0,
            self::number($price, 'cacheWrite', false) ?? 0.0,
            self::number($price, 'cacheWrite1h', false),
            $above,
        );
    }

    /**
     * @param array<mixed> $price
     * @throws ConfigurationException
     */
    private static function number(array $price, string $key, bool $required): ?float
    {
        $value = $price[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if ($value === null && !$required) {
            return null;
        }

        throw new ConfigurationException('Price "' . $key . '" must be a number.');
    }
}
