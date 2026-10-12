<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit;

use Ldkafka\Ai\Usage;
use PHPUnit\Framework\TestCase;

final class UsageTest extends TestCase
{
    public function testPromptTokensCountsCacheReadsAndWrites(): void
    {
        self::assertSame(60, (new Usage(10, 99, 20, 30))->promptTokens());
    }

    public function testAddSumsCountersAndDetails(): void
    {
        $sum = (new Usage(1, 2, 3, 4, 1, ['cacheWrite1h' => 2]))->add(new Usage(10, 20, 30, 40, 5, ['cacheWrite1h' => 3, 'other' => 1]));

        self::assertEquals(new Usage(11, 22, 33, 44, 6, ['cacheWrite1h' => 5, 'other' => 1]), $sum);
    }
}
