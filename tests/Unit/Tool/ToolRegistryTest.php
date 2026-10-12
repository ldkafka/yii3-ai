<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Tool;

use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Tests\Support\CallbackTool;
use Ldkafka\Ai\Tool\ToolRegistry;
use Ldkafka\Ai\Tool\ToolSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class ToolRegistryTest extends TestCase
{
    public function testWithIsImmutableAndSpecsKeepOrder(): void
    {
        $a = self::tool('a');
        $b = self::tool('b');
        $registry = new ToolRegistry([$a]);
        $more = $registry->with($b);

        self::assertNull($registry->get('b'));
        self::assertSame($b, $more->get('b'));
        self::assertEquals([ToolSpec::fromTool($a), ToolSpec::fromTool($b)], $more->specs());
    }

    public function testOnlyKeepsTheNamedTools(): void
    {
        $registry = (new ToolRegistry([self::tool('a'), self::tool('b'), self::tool('c')]))->only('c', 'a');

        self::assertSame(['c', 'a'], array_map(static fn (ToolSpec $s): string => $s->name, $registry->specs()));
    }

    public function testOnlyRejectsUnknownNames(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ToolRegistry([self::tool('a')]))->only('b');
    }

    public function testDuplicateName(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ToolRegistry([self::tool('a')]))->with(self::tool('a'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'dot' => ['logs.read'];
        yield 'space' => ['get weather'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[DataProvider('badNames')]
    public function testInvalidName(string $name): void
    {
        $this->expectException(ConfigurationException::class);
        new ToolRegistry([self::tool($name)]);
    }

    public function testValidNames(): void
    {
        $registry = new ToolRegistry([self::tool('Get-weather_2'), self::tool(str_repeat('a', 64))]);

        self::assertCount(2, $registry->specs());
    }

    private static function tool(string $name): CallbackTool
    {
        return new CallbackTool($name, static fn (array $arguments): string => '');
    }
}
