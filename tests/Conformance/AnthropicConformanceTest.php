<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Conformance;

use Ldkafka\Ai\Http\TransportInterface;
use Ldkafka\Ai\Model\ModelCatalog;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Provider\Anthropic\AnthropicProvider;
use Ldkafka\Ai\Provider\ProviderInterface;

use function getenv;
use function is_array;
use function is_string;

/**
 * The conformance scenarios against the Anthropic adapter.
 *
 * Live: `AI_LIVE=1 ANTHROPIC_API_KEY=... vendor/bin/phpunit --testsuite conformance`
 * (model `AI_LIVE_MODEL`, default claude-haiku-5-5); add `AI_RECORD=1` to rewrite the fixtures.
 */
final class AnthropicConformanceTest extends ConformanceTestCase
{
    protected function provider(TransportInterface $transport): ProviderInterface
    {
        $key = $this->live() ? (string) getenv('ANTHROPIC_API_KEY') : self::FAKE_KEY;

        return new AnthropicProvider($key, $transport);
    }

    protected function profile(): ModelProfile
    {
        return self::catalog()->get('claude-haiku-5-5');
    }

    protected function liveProfile(): ModelProfile
    {
        $model = getenv('AI_LIVE_MODEL');

        return self::catalog()->get(is_string($model) && $model !== '' ? $model : 'claude-haiku-5-5');
    }

    protected function fixtureDir(): string
    {
        return __DIR__ . '/fixtures/anthropic';
    }

    protected function liveReady(): bool
    {
        $key = getenv('ANTHROPIC_API_KEY');

        return is_string($key) && $key !== '';
    }

    /**
     * The catalog from the shipped params.
     */
    private static function catalog(): ModelCatalog
    {
        $params = require __DIR__ . '/../../config/params.php';
        self::assertIsArray($params);
        $config = $params['ldkafka/yii3-ai'] ?? null;
        self::assertIsArray($config);
        $models = $config['models'] ?? null;
        self::assertTrue(is_array($models));

        return ModelCatalog::fromConfig($models, 'claude-haiku-5-5');
    }
}
