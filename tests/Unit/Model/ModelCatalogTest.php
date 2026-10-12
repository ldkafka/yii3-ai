<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Model;

use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Model\Capability;
use Ldkafka\Ai\Model\ModelCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelCatalogTest extends TestCase
{
    public function testShippedParamsBuildTheFourClaudeModels(): void
    {
        $params = require __DIR__ . '/../../../config/params.php';
        self::assertIsArray($params);
        $config = $params['ldkafka/yii3-ai'];
        self::assertIsArray($config);
        self::assertIsArray($config['models']);
        self::assertIsString($config['defaultModel']);

        $catalog = ModelCatalog::fromConfig($config['models'], $config['defaultModel']);

        self::assertSame(['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-1'], $catalog->names());
        self::assertSame('claude-opus-5-5', $catalog->get()->name);
        $haiku = $catalog->get('claude-haiku-5-5');
        self::assertSame('anthropic', $haiku->provider);
        self::assertSame(1_000_000, $haiku->maxContext);
        self::assertTrue($haiku->supports(Capability::PromptCache));
        self::assertFalse($haiku->supports(Capability::ServerState));
        self::assertNotNull($haiku->price);
        self::assertArrayHasKey(100_000, $haiku->price->above);
    }

    public function testIdDefaultsToTheName(): void
    {
        $catalog = ModelCatalog::fromConfig(['m' => ['provider' => 'p', 'maxContext' => 10, 'maxOutput' => 5, 'maxTokens' => 5, 'options' => ['a' => 1]]], 'm');

        self::assertSame('m', $catalog->get('m')->id);
        self::assertNull($catalog->get('m')->price);
        self::assertSame(['a' => 1], $catalog->get('m')->options);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalid(): iterable
    {
        $ok = ['provider' => 'p', 'maxContext' => 10, 'maxOutput' => 5, 'maxTokens' => 5];
        yield 'unknown default' => [['m' => $ok], 'x'];
        yield 'no provider' => [['m' => ['maxContext' => 10, 'maxOutput' => 5, 'maxTokens' => 5]], 'm'];
        yield 'bad capability' => [['m' => $ok + ['capabilities' => ['telepathy']]], 'm'];
        yield 'bad limit' => [['m' => ['maxContext' => 0] + $ok], 'm'];
        yield 'bad price' => [['m' => $ok + ['price' => 'cheap']], 'm'];
        yield 'bad entry' => [[0 => $ok], 'm'];
    }

    /**
     * @param array<mixed> $models
     */
    #[DataProvider('invalid')]
    public function testInvalidConfig(array $models, string $default): void
    {
        $this->expectException(ConfigurationException::class);
        ModelCatalog::fromConfig($models, $default);
    }

    public function testUnknownName(): void
    {
        $catalog = ModelCatalog::fromConfig(['m' => ['provider' => 'p', 'maxContext' => 10, 'maxOutput' => 5, 'maxTokens' => 5]], 'm');

        $this->expectException(ConfigurationException::class);
        $catalog->get('other');
    }

    public function testNonFiniteModelOptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \Ldkafka\Ai\Model\ModelProfile('m', 'p', 'm', [], 10, 5, 5, null, ['top_p' => -INF]);
    }
}
