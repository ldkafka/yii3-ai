<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Internal;

use Ldkafka\Ai\Internal\Json;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    public function testDecodeKeepEmptyObjectsRoundTrips(): void
    {
        $json = '{"a":{},"b":[],"c":{"d":{},"e":[{}]},"f":1.0,"g":"é/x"}';

        $decoded = Json::decodeKeepEmptyObjects($json);

        self::assertIsArray($decoded);
        self::assertSame($json, Json::encode($decoded));
    }

    public function testReaders(): void
    {
        $data = ['s' => 'x', 'i' => 3, 'a' => [1], 'n' => null];

        self::assertSame('x', Json::string($data, 's'));
        self::assertNull(Json::string($data, 'i'));
        self::assertSame(3, Json::int($data, 'i'));
        self::assertSame(0, Json::int($data, 's'));
        self::assertSame([1], Json::array($data, 'a'));
        self::assertSame([], Json::array($data, 'n'));
    }

    public function testNumericKeyedObjectsStayObjects(): void
    {
        $json = '{"properties":{"0":{"type":"string"},"1":{}},"list":[{"0":"a"}],"sparse":{"2":"x"}}';

        $decoded = Json::decodeKeepEmptyObjects($json);

        self::assertIsArray($decoded);
        self::assertSame($json, Json::encode($decoded));
    }
}
