<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Model;

use Ldkafka\Ai\Cost;
use Ldkafka\Ai\Usage;

use function max;
use function min;

/**
 * Usage times price, at list price.
 *
 * The tier is the highest `above` threshold the prompt size exceeds, else the base price. Reasoning
 * tokens are inside the output count and are not added again. One-hour cache writes
 * (`details['cacheWrite1h']`) are priced at the 1h rate.
 */
final class CostCalculator
{
    /**
     * The estimated cost of one usage.
     */
    public function cost(Usage $usage, Price $price): Cost
    {
        $prompt = $usage->promptTokens();
        foreach ($price->above as $threshold => $tier) {
            if ($prompt > $threshold) {
                $price = $tier;
                break;
            }
        }

        $write1h = min($usage->cacheWriteTokens, max(0, $usage->details['cacheWrite1h'] ?? 0));
        $write5m = $usage->cacheWriteTokens - $write1h;

        return new Cost(
            $usage->inputTokens * $price->input / 1_000_000,
            $usage->outputTokens * $price->output / 1_000_000,
            $usage->cacheReadTokens * $price->cacheRead / 1_000_000,
            ($write5m * $price->cacheWrite + $write1h * ($price->cacheWrite1h ?? $price->cacheWrite)) / 1_000_000,
        );
    }
}
