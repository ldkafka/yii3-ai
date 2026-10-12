<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit;

use Ldkafka\Ai\Client;
use Ldkafka\Ai\Context\Chat;
use Ldkafka\Ai\Context\ContextPolicyInterface;
use Ldkafka\Ai\Context\ConversationStoreInterface;
use Ldkafka\Ai\Context\DbConversationStore;
use Ldkafka\Ai\Context\FullReplay;
use Ldkafka\Ai\Context\InMemoryConversationStore;
use Ldkafka\Ai\Context\TokenBudgetWindow;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Http\CurlTransport;
use Ldkafka\Ai\Http\TransportInterface;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Provider\Anthropic\AnthropicProvider;
use Ldkafka\Ai\Provider\ProviderRegistry;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Tool\Runner;
use Ldkafka\Ai\Http\HttpResponse;
use Ldkafka\Ai\Tests\Support\QueueTransport;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function array_replace_recursive;
use function getenv;
use function putenv;

/**
 * config/params.php and config/di.php resolve in yiisoft/di.
 */
final class ConfigTest extends TestCase
{
    private string|false $envKey = false;

    protected function setUp(): void
    {
        $this->envKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');
    }

    protected function tearDown(): void
    {
        putenv($this->envKey === false ? 'ANTHROPIC_API_KEY' : 'ANTHROPIC_API_KEY=' . $this->envKey);
    }

    public function testDefaultsResolveClientRunnerAndChat(): void
    {
        $container = $this->container();

        self::assertInstanceOf(Client::class, $container->get(Client::class));
        self::assertInstanceOf(Runner::class, $container->get(Runner::class));
        self::assertInstanceOf(Chat::class, $container->get(Chat::class));
        self::assertInstanceOf(CurlTransport::class, $container->get(TransportInterface::class));
        self::assertInstanceOf(FullReplay::class, $container->get(ContextPolicyInterface::class));
        self::assertInstanceOf(InMemoryConversationStore::class, $container->get(ConversationStoreInterface::class));
        $registry = $container->get(ProviderRegistry::class);
        self::assertInstanceOf(ProviderRegistry::class, $registry);
        self::assertInstanceOf(AnthropicProvider::class, $registry->get('anthropic'));

        $client = $container->get(Client::class);
        self::assertInstanceOf(Client::class, $client);
        self::assertSame('claude-opus-5-5', $client->model()->name);
    }

    public function testMissingKeyFailsAtTheFirstCallNotAtBoot(): void
    {
        $client = $this->container()->get(Client::class);
        self::assertInstanceOf(Client::class, $client);

        $this->expectException(ConfigurationException::class);
        $client->complete(new Request([Message::user('x')]));
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testWindowPolicyAndDbStore(): void
    {
        $container = $this->container([
            'context' => ['policy' => 'window', 'budgetTokens' => 5000],
            'store' => ['type' => 'db'],
            'runner' => ['maxCostUsd' => 0.5],
        ], [
            ConnectionInterface::class => static fn (): Connection => new Connection(new Driver('sqlite::memory:'), new SchemaCache(new ArrayCache())),
        ]);

        self::assertInstanceOf(TokenBudgetWindow::class, $container->get(ContextPolicyInterface::class));
        self::assertInstanceOf(DbConversationStore::class, $container->get(ConversationStoreInterface::class));
        self::assertInstanceOf(Chat::class, $container->get(Chat::class));
    }

    /**
     * @param array<string, mixed> $override
     * @param array<string, mixed> $extra
     */
    private function container(array $override = [], array $extra = []): Container
    {
        $params = require __DIR__ . '/../../config/params.php';
        self::assertIsArray($params);
        $own = $params['ldkafka/yii3-ai'] ?? null;
        self::assertIsArray($own);
        $params['ldkafka/yii3-ai'] = array_replace_recursive($own, $override);
        $definitions = (static function (array $params): mixed {
            return require __DIR__ . '/../../config/di.php';
        })($params);
        self::assertIsArray($definitions);

        return new Container(ContainerConfig::create()->withDefinitions($extra + $definitions));
    }

    public function testWorkspaceIdFromParams(): void
    {
        $transport = new QueueTransport([new HttpResponse(200, [], '{"content":[],"stop_reason":"end_turn","usage":{}}')]);
        $container = $this->container(
            ['providers' => ['anthropic' => ['apiKey' => 'k', 'workspaceId' => 'wrkspc_9']]],
            [TransportInterface::class => $transport],
        );
        $client = $container->get(Client::class);
        self::assertInstanceOf(Client::class, $client);

        $client->complete(new Request([Message::user('x')]));

        self::assertSame('wrkspc_9', $transport->requests[0]->headers['anthropic-workspace-id'] ?? null);
    }
}
