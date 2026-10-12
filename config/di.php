<?php

declare(strict_types=1);

use Ldkafka\Ai\Client;
use Ldkafka\Ai\Context\ContextPolicyInterface;
use Ldkafka\Ai\Context\ConversationStoreInterface;
use Ldkafka\Ai\Context\DbConversationStore;
use Ldkafka\Ai\Context\FullReplay;
use Ldkafka\Ai\Context\InMemoryConversationStore;
use Ldkafka\Ai\Context\TokenBudgetWindow;
use Ldkafka\Ai\Http\CurlTransport;
use Ldkafka\Ai\Http\TransportInterface;
use Ldkafka\Ai\Model\ModelCatalog;
use Ldkafka\Ai\Pipeline\RetryMiddleware;
use Ldkafka\Ai\Pipeline\RetryPolicy;
use Ldkafka\Ai\Provider\Anthropic\AnthropicProvider;
use Ldkafka\Ai\Provider\ProviderRegistry;
use Ldkafka\Ai\Tool\Runner;
use Ldkafka\Ai\Tool\ToolRegistry;
use Psr\Log\LoggerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Definitions\Reference;

/** @var array<string, mixed> $params */

$config = (array) ($params['ldkafka/yii3-ai'] ?? []);
$anthropic = (array) ($config['providers']['anthropic'] ?? []);
$http = (array) ($config['http'] ?? []);
$retry = (array) ($config['retry'] ?? []);
$runner = (array) ($config['runner'] ?? []);
$context = (array) ($config['context'] ?? []);
$store = (array) ($config['store'] ?? []);

$apiKey = (string) ($anthropic['apiKey'] ?? '');
if ($apiKey === '') {
    $env = getenv('ANTHROPIC_API_KEY');
    $apiKey = is_string($env) ? $env : '';
}

return [
    TransportInterface::class => [
        'class' => CurlTransport::class,
        '__construct()' => [
            'timeout' => (float) ($http['timeout'] ?? 600),
            'connectTimeout' => (float) ($http['connectTimeout'] ?? 10),
        ],
    ],

    AnthropicProvider::class => [
        '__construct()' => [
            'apiKey' => $apiKey,
            'transport' => Reference::to(TransportInterface::class),
            'baseUrl' => (string) ($anthropic['baseUrl'] ?? 'https://api.anthropic.com'),
            'version' => (string) ($anthropic['version'] ?? '2023-06-01'),
            'betas' => array_values((array) ($anthropic['betas'] ?? [])),
            'workspaceId' => isset($anthropic['workspaceId']) && $anthropic['workspaceId'] !== '' ? (string) $anthropic['workspaceId'] : null,
        ],
    ],

    ProviderRegistry::class => [
        '__construct()' => [
            'providers' => [AnthropicProvider::NAME => Reference::to(AnthropicProvider::class)],
        ],
    ],

    ModelCatalog::class => static fn (): ModelCatalog => ModelCatalog::fromConfig(
        (array) ($config['models'] ?? []),
        (string) ($config['defaultModel'] ?? ''),
    ),

    RetryPolicy::class => [
        '__construct()' => [
            'maxRetries' => (int) ($retry['maxRetries'] ?? 2),
            'baseDelayMs' => (int) ($retry['baseDelayMs'] ?? 500),
            'maxDelayMs' => (int) ($retry['maxDelayMs'] ?? 8000),
            'maxRetryAfterSeconds' => (int) ($retry['maxRetryAfterSeconds'] ?? 60),
        ],
    ],

    RetryMiddleware::class => [
        '__construct()' => [
            'policy' => Reference::to(RetryPolicy::class),
            'logger' => Reference::optional(LoggerInterface::class),
        ],
    ],

    Client::class => [
        '__construct()' => [
            'models' => Reference::to(ModelCatalog::class),
            'providers' => Reference::to(ProviderRegistry::class),
            'middleware' => [Reference::to(RetryMiddleware::class)],
            'logger' => Reference::optional(LoggerInterface::class),
        ],
    ],

    // Empty: the application redefines it with its tools.
    ToolRegistry::class => ToolRegistry::class,

    ContextPolicyInterface::class => ($context['policy'] ?? 'replay') === 'window'
        ? [
            'class' => TokenBudgetWindow::class,
            '__construct()' => ['budgetTokens' => (int) ($context['budgetTokens'] ?? 100_000)],
        ]
        : FullReplay::class,

    Runner::class => [
        '__construct()' => [
            'client' => Reference::to(Client::class),
            'tools' => Reference::to(ToolRegistry::class),
            'context' => Reference::to(ContextPolicyInterface::class),
            'maxSteps' => (int) ($runner['maxSteps'] ?? 10),
            'maxCostUsd' => isset($runner['maxCostUsd']) ? (float) $runner['maxCostUsd'] : null,
            'logger' => Reference::optional(LoggerInterface::class),
        ],
    ],

    ConversationStoreInterface::class => ($store['type'] ?? 'memory') === 'db'
        ? [
            'class' => DbConversationStore::class,
            '__construct()' => [
                'db' => Reference::to(ConnectionInterface::class),
                'table' => (string) ($store['table'] ?? '{{%ai_message}}'),
            ],
        ]
        : InMemoryConversationStore::class,
];
