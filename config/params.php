<?php

declare(strict_types=1);

/**
 * Defaults for ldkafka/yii3-ai. Yii3 applications override single keys in their own params
 * (yiisoft/config merges recursively); plain PHP and Yii2 applications can require this file.
 *
 * Prices are USD per million tokens, list prices checked on 12 Oct 2026 against
 * https://platform.claude.com/docs/en/about-claude/pricing. A price change is a CHANGELOG line.
 */

$claude = static fn (string $id, array $price): array => [
    'provider' => 'anthropic',
    'id' => $id,
    'capabilities' => ['tools', 'vision', 'pdf', 'jsonSchema', 'promptCache', 'thinking'],
    'maxContext' => 1_000_000,
    'maxOutput' => 128_000,
    'maxTokens' => 16_000,
    'price' => $price,
    // Payload defaults, e.g. ['output_config' => ['effort' => 'high']] or ['thinking' => [...]].
    'options' => [],
];

return [
    'ldkafka/yii3-ai' => [
        'defaultModel' => 'claude-opus-5-5',
        'providers' => [
            'anthropic' => [
                // '' = getenv('ANTHROPIC_API_KEY'). Never commit a key.
                'apiKey' => '',
                'baseUrl' => 'https://api.anthropic.com',
                'version' => '2023-06-01',
                // Sent in the anthropic-beta header on every call.
                'betas' => [],
                // Sent as anthropic-workspace-id when set; required with a multi-workspace key.
                'workspaceId' => null,
            ],
        ],
        // Keys are the names used in Request::$model.
        'models' => [
            'claude-opus-5-5' => $claude('claude-opus-5-5', [
                'input' => 4.00, 'output' => 20.00, 'cacheRead' => 0.20, 'cacheWrite' => 5.00, 'cacheWrite1h' => 8.00,
            ]),
            'claude-sonnet-5-5' => $claude('claude-sonnet-5-5', [
                'input' => 2.00, 'output' => 10.00, 'cacheRead' => 0.10, 'cacheWrite' => 2.50, 'cacheWrite1h' => 4.00,
            ]),
            'claude-haiku-5-5' => $claude('claude-haiku-5-5', [
                'input' => 0.10, 'output' => 0.50, 'cacheRead' => 0.01, 'cacheWrite' => 0.125, 'cacheWrite1h' => 0.20,
                // Prompts over 100K tokens.
                'above' => [
                    100_000 => ['input' => 0.50, 'output' => 2.50, 'cacheRead' => 0.05, 'cacheWrite' => 0.625, 'cacheWrite1h' => 1.00],
                ],
            ]),
            'claude-fable-5-1' => $claude('claude-fable-5-1', [
                'input' => 10.00, 'output' => 50.00, 'cacheRead' => 0.25, 'cacheWrite' => 12.50, 'cacheWrite1h' => 20.00,
            ]),
        ],
        // Seconds per attempt; 600 s is the official SDKs' limit.
        'http' => ['timeout' => 600, 'connectTimeout' => 10],
        'retry' => ['maxRetries' => 2, 'baseDelayMs' => 500, 'maxDelayMs' => 8_000, 'maxRetryAfterSeconds' => 60],
        'runner' => ['maxSteps' => 10, 'maxCostUsd' => null],
        // policy: 'replay' (send everything) or 'window' (TokenBudgetWindow with budgetTokens).
        'context' => ['policy' => 'replay', 'budgetTokens' => 100_000],
        // type: 'memory' or 'db' (DbConversationStore; run the migration first).
        'store' => ['type' => 'memory', 'table' => '{{%ai_message}}'],
    ],
];
