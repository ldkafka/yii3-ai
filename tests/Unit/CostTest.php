<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit;

use Ldkafka\Ai\Cost;
use PHPUnit\Framework\TestCase;

final class CostTest extends TestCase
{
    public function testTotalAndAdd(): void
    {
        $cost = (new Cost(1.0, 2.0, 0.5, 0.25))->add(new Cost(1.0, 1.0, 1.0, 1.0));

        self::assertEquals(new Cost(2.0, 3.0, 1.5, 1.25), $cost);
        self::assertEqualsWithDelta(7.75, $cost->total(), 1e-12);
    }
}
