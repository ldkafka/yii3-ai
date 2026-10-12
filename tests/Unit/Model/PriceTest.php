<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Model;

use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Model\Price;
use PHPUnit\Framework\TestCase;

final class PriceTest extends TestCase
{
    public function testFromConfigWithTiersSortedHighestFirst(): void
    {
        $price = Price::fromConfig([
            'input' => 1, 'output' => 2.5,
            'above' => [10 => ['input' => 2, 'output' => 3], 100 => ['input' => 4, 'output' => 5]],
        ]);

        self::assertSame(1.0, $price->input);
        self::assertSame(0.0, $price->cacheRead);
        self::assertNull($price->cacheWrite1h);
        self::assertSame([100, 10], array_keys($price->above));
    }

    public function testMissingRequiredNumber(): void
    {
        $this->expectException(ConfigurationException::class);
        Price::fromConfig(['input' => 1]);
    }

    public function testNonNumericValue(): void
    {
        $this->expectException(ConfigurationException::class);
        Price::fromConfig(['input' => '1', 'output' => 1]);
    }

    public function testBadTier(): void
    {
        $this->expectException(ConfigurationException::class);
        Price::fromConfig(['input' => 1, 'output' => 1, 'above' => ['big' => ['input' => 1, 'output' => 1]]]);
    }
}
