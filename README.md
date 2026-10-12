# yii3-ai

[![tests](https://github.com/ldkafka/yii3-ai/actions/workflows/tests.yml/badge.svg)](https://github.com/ldkafka/yii3-ai/actions/workflows/tests.yml)
[![Packagist version](https://img.shields.io/packagist/v/ldkafka/yii3-ai.svg)](https://packagist.org/packages/ldkafka/yii3-ai)
[![PHP version](https://img.shields.io/packagist/php-v/ldkafka/yii3-ai.svg)](https://packagist.org/packages/ldkafka/yii3-ai)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE)

One request and response format for AI model APIs, in plain PHP 8.3+ with a thin Yii3 config layer.
No vendor SDKs: the runtime needs `ext-curl`, `ext-json` and `psr/log`.

- Requests, messages, tool calls, results, stop reasons and usage have one shape for every provider.
  v0.1 ships the Anthropic Messages API adapter; more adapters follow (see [ROADMAP.md](ROADMAP.md)).
- Every model a request names is in a catalog with its capabilities and price. A request that needs
  a capability the model lacks fails before any HTTP call.
- Every response carries usage, a cost estimate at list price and the latency.
- A bounded tool loop (`Runner`), persistent conversations (`Chat`) with a memory or database store,
  and a context window policy.
- A client for tools on remote MCP servers.

Status: 0.1, Anthropic only for now. The API may change before 1.0 (the CHANGELOG says how). The conformance
fixtures are hand-written until they are re-recorded from the live API. Plans: [ROADMAP.md](ROADMAP.md).

## Installation

```bash
composer require ldkafka/yii3-ai
```

Optional packages: `yiisoft/db` and `yiisoft/db-migration` for the database store,
`psr/http-client` and `psr/http-factory` for `Psr18Transport`, `yiisoft/config` for Yii3 wiring.

## Quick start (plain PHP)

```php
use Ldkafka\Ai\Client;
use Ldkafka\Ai\Http\CurlTransport;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Model\ModelCatalog;
use Ldkafka\Ai\Pipeline\RetryMiddleware;
use Ldkafka\Ai\Provider\Anthropic\AnthropicProvider;
use Ldkafka\Ai\Provider\ProviderRegistry;
use Ldkafka\Ai\Request;

$params = (require 'vendor/ldkafka/yii3-ai/config/params.php')['ldkafka/yii3-ai'];

$client = new Client(
    ModelCatalog::fromConfig($params['models'], 'claude-opus-5-5'),
    new ProviderRegistry(['anthropic' => new AnthropicProvider((string) getenv('ANTHROPIC_API_KEY'), new CurlTransport())]),
    [new RetryMiddleware()],
);

$answer = $client->complete(new Request([Message::user('Summarise: ...')], model: 'claude-haiku-5-5'));
echo $answer->text(), sprintf(' ($%.5f)', $answer->cost?->total());
```

`Request` holds everything one call needs: `messages`, `model` (a catalog name, '' for the default),
`system`, `tools`, `toolChoice`, `responseFormat`, `maxTokens`, `temperature`, `stop`, `metadata`,
`cache` (prompt-cache hints), `requires` (capabilities) and `providerOptions` (merged into the
provider's payload last). Append `$answer->message` to your history as it is: it keeps tool calls
and the model's thinking blocks, which the provider needs to see again.

## Quick start (Yii3)

The package registers `config/params.php` and `config/di.php` with `yiisoft/config`. Set the key in
the environment (`ANTHROPIC_API_KEY`) or in your params, then take the classes from the container:

```php
final class SupportController
{
    public function __construct(private Chat $chat)
    {
    }

    public function reply(string $ticketId, string $text): string
    {
        return $this->chat->send($ticketId, $text)->text();
    }
}
```

A missing API key fails at the first call with a `ConfigurationException`, not at boot.

## Yii2

The core has no Yii3 dependency. In a Yii2 app, build `Client`, `Runner` and `Chat` in a container
definition from the same params array, and implement `ConversationStoreInterface` with
`yii\db\Query` on the table described below (create it with a Yii2 migration).

## Configuration

`config/params.php` holds the defaults under the key `ldkafka/yii3-ai`; override single keys in your
own params.

| Key | Default | Meaning |
|---|---|---|
| `defaultModel` | `claude-opus-5-5` | The model when a request names none |
| `providers.anthropic.apiKey` | `''` | `''` reads `ANTHROPIC_API_KEY`. Never commit a key |
| `providers.anthropic.baseUrl`, `version`, `betas` | API URL, `2023-06-01`, `[]` | `betas` go in the `anthropic-beta` header |
| `providers.anthropic.workspaceId` | `null` | Sent as `anthropic-workspace-id`; needed with a multi-workspace key |
| `models` | four Claude 5 models | Per model: `provider`, `id`, `capabilities`, `maxContext`, `maxOutput`, `maxTokens`, `price`, `options` |
| `http.timeout`, `http.connectTimeout` | 600, 10 | Seconds per attempt |
| `retry` | 2 retries, 500 ms base, 8 s cap, 60 s longest retry-after | See "Errors and retries" |
| `runner.maxSteps`, `runner.maxCostUsd` | 10, null | Limits per run |
| `context.policy`, `context.budgetTokens` | `replay`, 100000 | `replay` sends all history; `window` uses `TokenBudgetWindow` |
| `store.type`, `store.table` | `memory`, `{{%ai_message}}` | `db` uses `DbConversationStore` |

Model `options` are payload defaults merged before `providerOptions`; maps merge recursively and
lists are replaced. Use them for provider settings that have no typed field, for example
`['output_config' => ['effort' => 'high']]` or a `thinking` block.

## Models and capabilities

| Name | Context / max output | Input · output · cache read · 5m write · 1h write ($ per million tokens) |
|---|---|---|
| `claude-opus-5-5` | 1M / 128K | 4 · 20 · 0.20 · 5 · 8 |
| `claude-sonnet-5-5` | 1M / 128K | 2 · 10 · 0.10 · 2.50 · 4 |
| `claude-haiku-5-5` | 1M / 128K | 0.10 · 0.50 · 0.01 · 0.125 · 0.20; prompts over 100K: 0.50 · 2.50 · 0.05 · 0.625 · 1 |
| `claude-fable-5-1` | 1M / 128K | 10 · 50 · 0.25 · 12.50 · 20 |

Prices were checked on 12 Oct 2026 against the
[Anthropic pricing page](https://platform.claude.com/docs/en/about-claude/pricing). The cost is a list-price
estimate; the provider's invoice is authoritative. Batch discounts, regional pricing and server-tool
fees are not modelled.

Capabilities: `tools`, `vision`, `pdf`, `jsonSchema`, `promptCache`, `serverState`, `thinking`. The
client derives what a request needs (tools, images anywhere including tool results, PDFs, a response
format, plus `requires`) and throws `CapabilityException` before any HTTP call when the model lacks
any of it. Cache hints are advisory: a model without `promptCache` ignores them unless the request
requires that capability.

## Tools and the Runner

A tool implements `ToolInterface`: `getName()`, `getDescription()`, `getInputSchema()` and
`handle(array $arguments): string|array` (a string or a list of `Text`, `Image` and `Document`). The
getters match `ldkafka/yii3-mcp-server`'s `McpToolInterface`, so one class can implement both.

```php
$runner = new Runner($client, new ToolRegistry([new BookingLookupTool($bookings)]), maxSteps: 6, maxCostUsd: 0.25);
$result = $runner->run(new Request([Message::user('Where is booking 1234?')], system: 'You are support.'));

echo $result->text(), ' ', $result->status->value, ' ', $result->steps;
```

Rules of the loop:
- The run offers exactly the registry's tools on every step; a request with its own `tools` is rejected.
- A forced `toolChoice` (`ToolChoice::required()` or `ToolChoice::tool()`) applies to the first step only;
  later steps use `auto` with the same parallel setting, so the model can answer and the run can end.
- It stops at `maxSteps` (`StepLimit`) or once the summed cost reaches `maxCostUsd` (`BudgetExhausted`).
  The budget is soft: one call may overshoot it.
- All tool calls of a step are answered in one user message, in call order, so a stopped run leaves
  a valid history.
- A `ToolException` message is shown to the model. Any other exception becomes "Tool X failed" for the
  model and is logged as a warning locally, because it may hold internals.
- Arguments are not validated against the schema: validate in the tool and throw `ToolException`.

## Remote MCP tools

`McpHttpClient` calls tools on a remote MCP server over the Streamable HTTP transport.
`RemoteMcpToolSource` lists a server's tools, optionally limited to an allow-list, as `RemoteMcpTool`
objects for a `ToolRegistry`.

```php
use Ldkafka\Ai\Mcp\McpHttpClient;
use Ldkafka\Ai\Mcp\RemoteMcpToolSource;

$mcp = new McpHttpClient(
    'https://mcp.example.com/mcp',
    new CurlTransport(timeout: 60.0),
    (string) getenv('MCP_TOKEN'),          // sent as Authorization: Bearer
    userAgent: 'example-watch/1.0',        // set your own
);
$tools = (new RemoteMcpToolSource($mcp, allow: ['prod_check', 'app_logs']))->registry();
$result = (new Runner($client, $tools, maxSteps: 8))->run(new Request([Message::user('What changed since 06:00 UTC?')]));
```

- Set a `userAgent` of your own. Some servers and proxies refuse library default agents.
- The `initialize` handshake runs once, before the first call. The client proposes protocol version
  `2025-11-25` and accepts `2025-11-25`, `2025-06-18` or `2025-03-26` (ldkafka/yii3-mcp-server
  answers `2025-06-18`). The stateless `2026-07-28` revision is not supported yet: a server that
  speaks only that revision fails the handshake with an `McpException`.
- Sessions (`Mcp-Session-Id`) and event-stream answers are handled. A 404 on a session starts a new
  session and resends once. Nothing else is retried, so a refused client (401, 403) does not keep
  knocking. `tools/list` follows at most 100 pages and stops if the server repeats a cursor.
- Text content becomes `Text`, images become `Image` (image data that is not valid base64 becomes an
  error result); other content becomes a short text note. A
  result with `isError: true` and a JSON-RPC "Invalid params" error become a `ToolException`, so
  the model reads the server's message. Other failures surface as `McpException`, which the Runner
  reports to the model as "Tool X failed".
- Tool names that break the `ToolInterface` name rule are skipped with a warning.

## Conversations and the migration

`Chat` keeps conversations in a `ConversationStoreInterface` and runs each message through the
Runner. It stores the user message before the call (so it survives a failure), stores each message
the run appends with its model, usage and cost, and answers tool calls left open by a run that died.

```php
$chat = new Chat($runner, new InMemoryConversationStore());
$result = $chat->send('ticket-42', 'Can I change the dates?', new Request(system: 'You are support.'));
```

Conversation ids are yours, 1 to 64 characters. Keep `system` and the tool set fixed per conversation.

`Chat` assumes one writer per conversation: two `send()` calls on the same id at the same time can
interleave their rows. Serialise sends per conversation (a lock, or one queue worker per id).

`DbConversationStore` uses `yiisoft/db` and the table `{{%ai_message}}` (id, conversation_id, role,
parts JSON, provider, model, stop_reason, token counts, cost_usd, created_at). The migration is not
registered automatically. To create the table, add the namespace and run the migrations:

```php
// params.php
'yiisoft/db-migration' => [
    'sourceNamespaces' => ['Ldkafka\\Ai\\Migration'],
],
```

```bash
./yii migrate:up
```

Spend per conversation is `SUM(cost_usd)`. Images and PDFs given as base64 are stored inline; pass
URLs to keep the table small.

`TokenBudgetWindow` (`context.policy = window`) sends the newest whole turns within a token budget.
When it has to trim, it drops whole turns from the oldest down to 75% of the budget, never splits a
tool call from its result, and removes earlier thinking blocks, which the provider rejects once the
history before them changed.

## Errors and retries

Every exception extends `Ldkafka\Ai\Exception\AiException`. Messages never contain API keys or
prompts.

| Exception | When |
|---|---|
| `ConfigurationException` | Unknown model or provider, missing key, invalid params |
| `CapabilityException` | The model lacks what the request needs (`$e->missing`) |
| `ProviderException` | The provider answered with an error: `$e->kind` (`ErrorKind`), `httpStatus`, `errorType`, `requestId`, `retryAfter` |
| `TransportException` | No answer: DNS, TLS, connect or timeout (`isTimeout()`) |
| `ContextOverflowException` | The newest turn alone exceeds the window budget |
| `ToolException` | Thrown by tools; its message goes to the model |
| `McpException` | A remote MCP server failed (`httpStatus`, `rpcCode`) |

`RetryMiddleware` retries rate limits that carry `retry-after`, overloaded and server errors,
provider timeouts and connection failures before the request was sent: at most 2 retries, waiting
`retry-after` when it is 60 seconds or less, otherwise exponential backoff with jitter. It does not
retry a 429 without `retry-after` (the monthly spend cap), a read timeout or a connection dropped after
the request was sent (`TransportException::isAfterSend()`: the call may have run and been billed), or
a `retry-after` over 60 seconds (the exception carries `retryAfter`, so a queue job can reschedule).

## Transports

`CurlTransport` is the default: one reused connection, TLS verification on, no redirects.
`Psr18Transport` uses any PSR-18 client, with that client's timeouts and redirect rules. Guzzle and the
Symfony client follow redirects by default; turn that off in the client you inject, because a redirect
can resend the request, with its `Authorization` header, to another URL.

## Logging

All logging is PSR-3 and optional. The client logs one info line per call (provider, model, stop
reason, token counts, cost, latency, attempts, request id) and a warning per failure; the retry
middleware logs each retry; the Runner logs each failing tool. Prompts, message text, tool arguments
and results, keys and headers are never logged. Keys and tokens are `#[\SensitiveParameter]`, so
stack traces show no key or request, and `var_dump()` / `print_r()` of a provider, an MCP client or an
`HttpRequest` show `[redacted]` in their place.

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The database tests need `pdo_sqlite` and are skipped without it.

The conformance suite (`tests/Conformance`) runs 20 scenarios through a real `Client` on fixtures
and checks every outgoing request against the one in the fixture. The fixtures are hand-written from
the documented API shapes and are re-recorded from the live API before release; `"synthetic": true`
marks those not yet recorded. To run the suite against the live API
(about a cent with Haiku):

```bash
AI_LIVE=1 ANTHROPIC_API_KEY=... vendor/bin/phpunit --testsuite conformance
AI_LIVE=1 AI_RECORD=1 ANTHROPIC_API_KEY=... vendor/bin/phpunit --testsuite conformance   # rewrite the fixtures
```

`AI_LIVE_MODEL` picks the model (default `claude-haiku-5-5`). Scenarios that cannot be triggered on
demand (errors, refusals, server tools) are skipped in live runs. Recorded fixtures keep only
allow-listed headers and never the key. Live runs are manual, never in CI.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
