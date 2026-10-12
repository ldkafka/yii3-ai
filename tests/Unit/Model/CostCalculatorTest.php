<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Model;

use Ldkafka\Ai\Model\CostCalculator;
use Ldkafka\Ai\Model\Price;
use Ldkafka\Ai\Usage;
use PHPUnit\Framework\TestCase;

final class CostCalculatorTest extends TestCase
{
    private Price $haiku;

    protected function setUp(): void
    {
        $this->haiku = Price::fromConfig([
            'input' => 0.10, 'output' => 0.50, 'cacheRead' => 0.01, 'cacheWrite' => 0.125, 'cacheWrite1h' => 0.20,
            'above' => [100_000 => ['input' => 0.50, 'output' => 2.50, 'cacheRead' => 0.05, 'cacheWrite' => 0.625, 'cacheWrite1h' => 1.00]],
        ]);
    }

    public function testBaseTierWithOneHourWrites(): void
    {
        $cost = (new CostCalculator())->cost(new Usage(1000, 500, 2000, 3000, 100, ['cacheWrite1h' => 1000]), $this->haiku);

        self::assertEqualsWithDelta(1000 * 0.10 / 1e6, $cost->input, 1e-15);
        self::assertEqualsWithDelta(500 * 0.50 / 1e6, $cost->output, 1e-15, 'Reasoning is inside output and not added again.');
        self::assertEqualsWithDelta(2000 * 0.01 / 1e6, $cost->cacheRead, 1e-15);
        self::assertEqualsWithDelta((2000 * 0.125 + 1000 * 0.20) / 1e6, $cost->cacheWrite, 1e-15);
    }

    public function testHigherTierWhenThePromptExceedsTheThreshold(): void
    {
        // 60K input + 30K cache read + 20K cache write = 110K prompt tokens.
        $cost = (new CostCalculator())->cost(new Usage(60_000, 1000, 30_000, 20_000), $this->haiku);

        self::assertEqualsWithDelta(60_000 * 0.50 / 1e6, $cost->input, 1e-15);
        self::assertEqualsWithDelta(1000 * 2.50 / 1e6, $cost->output, 1e-15);
        self::assertEqualsWithDelta(30_000 * 0.05 / 1e6, $cost->cacheRead, 1e-15);
        self::assertEqualsWithDelta(20_000 * 0.625 / 1e6, $cost->cacheWrite, 1e-15);
    }

    public function testExactlyAtTheThresholdStaysInTheBaseTier(): void
    {
        $cost = (new CostCalculator())->cost(new Usage(100_000), $this->haiku);

        self::assertEqualsWithDelta(100_000 * 0.10 / 1e6, $cost->input, 1e-15);
    }

    public function testOneHourWritesWithoutAOneHourPriceUseTheWritePrice(): void
    {
        $cost = (new CostCalculator())->cost(new Usage(0, 0, 0, 10, 0, ['cacheWrite1h' => 10]), new Price(1, 1, 0, 2));

        self::assertEqualsWithDelta(20 / 1e6, $cost->cacheWrite, 1e-15);
    }
}
