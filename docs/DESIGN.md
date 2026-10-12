# ldkafka/yii3-ai: design

Status: approved by the owner on 12 Oct 2026 with the decisions in §17, which added §5.10 (remote MCP tools).
Scope: phase 1 = **v0.1.0**.
Inputs: the owner's `yii3-google-cloud-logging` (the template), `yii3-mcp-server` (the tool contract), `yii2-google-gemini`
(lessons, §16) and Anthropic's API documentation (read 12 Oct 2026; sources at the end).

## 1. Goals and non-goals

**Goals**
- **One format for every provider**: requests, messages, tool calls, results, stop reasons and usage look the same for
  Claude, OpenAI and Gemini; switching provider is a config change.
- **Stable**: a small public API under semver; every adapter passes one conformance suite on recorded fixtures.
- **Slim**: the runtime needs PHP, `ext-curl`, `ext-json` and `psr/log`; everything else is optional.
- **Fast and efficient**: raw HTTP on a reused connection, no SDK bootstrap, prompt-cache hints, usage and cost on every response.
- **Pluggable**: transport, providers, middleware, context policy, conversation store and tools are interfaces.
- **No silent emulation**: capabilities are declared per model; a request that needs a missing one fails before any
  HTTP call. Provider data the core does not interpret (thinking, unknown blocks) round-trips untouched.
- **Framework-agnostic core** with a thin Yii3 config/DI layer; a Yii2 app (Aura) wires the same classes by hand.

**Non-goals**
- An agent framework (planners, multi-agent orchestration). The Runner is a bounded tool loop, nothing more.
- A vector database, a RAG pipeline, a prompt-template DSL.
- Wrapping vendor SDKs, or emulating what a model lacks (JSON by prompting, tools by parsing text).
- Exact billing: cost is a list-price estimate; the provider's invoice is authoritative.
- In v0.1: streaming, the Batch and Files APIs, server tools, computer use (reachable only through `providerOptions`).

## 2. Identity

| | |
|---|---|
| Composer | `ldkafka/yii3-ai`, type `library`, BSD-3-Clause, GitHub `ldkafka/yii3-ai` |
| Namespace | `Ldkafka\Ai\` → `src/`; tests `Ldkafka\Ai\Tests\` → `tests/` |
| PHP | `^8.3` |
| Requires | `ext-curl`, `ext-json`, `psr/log ^1.1 \|\| ^2.0 \|\| ^3.0` |
| Suggests | `yiisoft/config` (wiring), `yiisoft/db ^2.0` + `yiisoft/db-migration` (DB store), `psr/http-client` + `psr/http-factory` (PSR-18 transport), `ldkafka/yii3-mcp-server` (v1.0 bridge) |
| Dev | `phpunit/phpunit ^11.5 \|\| ^12.0`, `phpstan/phpstan ^2.1`, `yiisoft/db-sqlite`, `yiisoft/db-migration`, `yiisoft/di`, the two PSR HTTP packages |

**Namespace `Ldkafka\Ai`**: vendor plus product, as in the owner's newest package (`Ldkafka\GoogleCloudLogging`). No
"Yii3" in it: the core is framework-free and Aura (Yii2) will use it; the Yii3 part is two config files.

**PHP 8.3**: CI runs 8.3, 8.4 and 8.5 (§17). PHP 8.2 leaves security support on 31 Dec 2026 (within three
months), and Yii3 apps already need 8.2+. 8.3 adds typed class constants, `json_validate()`, `#[\Override]` and
clone-safe readonly properties to 8.2's `readonly` classes and `#[\SensitiveParameter]` (used on API keys).

**Conventions** (from the template): `declare(strict_types=1)`, `final` by default, value objects `final readonly`,
`use function` imports, a PHPDoc and full types on every method, exception messages that never contain keys or prompts.

## 3. Architecture

```
Chat ─► Runner ─► Client ─► middleware (Retry, …) ─► ProviderRegistry ─► AnthropicProvider ─► TransportInterface
 │        │         └ model lookup, capability check, defaults,          (pure AnthropicMapper)   (CurlTransport)
 │        │           cost, latency, one log line
 │        └ context policy (once per run), tool loop, step and cost limits
 └ ConversationStoreInterface (the source of truth)
```

An **adapter** is a pure mapper (Request → array, JSON → Response, HTTP error → exception; no I/O, fixture-tested)
plus a provider class that adds URL and headers and calls the transport once. Retries live in middleware.

**Transport: own interface, curl default, optional PSR-18 adapter.** `TransportInterface` has one method,
`CurlTransport` needs only `ext-curl`, and `Psr18Transport` serves apps that want their own HTTP stack. Not PSR-18
only, because (1) it adds a client and factories to every install for one POST; (2) PSR-18 has no per-request timeout
or abort, and v0.2 streaming needs incremental body reads that PSR-18 clients do not guarantee, while curl's write
callback does; (3) the cloud-logging package proved the pattern (a 60-line curl client, a fake transport in tests);
(4) one reused curl handle keeps the TLS connection alive between calls. The PSR-18 adapter costs ~50 lines.

## 4. Directory layout

```
yii3-ai/
├── .github/workflows/tests.yml     config/params.php, config/di.php     docs/DESIGN.md
├── src/           Client Request Response StopReason Usage Cost CacheHint CacheScope ResponseFormat
│   ├── Message/   Message Role Part Text Image Document ToolCall ToolResult Thinking ProviderPart
│   ├── Model/     Capability ModelProfile ModelCatalog Price CostCalculator
│   ├── Pipeline/  HandlerInterface MiddlewareInterface RetryPolicy RetryMiddleware
│   ├── Provider/  ProviderInterface ProviderRegistry   Anthropic/ AnthropicProvider AnthropicMapper
│   ├── Http/      TransportInterface HttpRequest HttpResponse CurlTransport Psr18Transport
│   ├── Tool/      ToolInterface ToolSpec ToolChoice ToolRegistry Runner RunResult RunStatus
│   ├── Context/   ConversationStoreInterface InMemoryConversationStore DbConversationStore ContextPolicyInterface
│   │              FullReplay TokenBudgetWindow TokenEstimatorInterface HeuristicTokenEstimator Chat
│   ├── Exception/ AiException ConfigurationException CapabilityException ProviderException ErrorKind
│   │              TransportException ContextOverflowException ToolException McpException
│   ├── Mcp/       McpHttpClient RemoteMcpTool RemoteMcpToolSource (§5.10)
│   ├── Internal/  Json (@internal: JSON encode/decode that keeps empty objects as {})
│   └── Migration/ M261020000000CreateAiMessageTable
├── tests/         Unit/ (one class per src class)   Support/ (FixtureTransport RecordingTransport FakeProvider echo-server.php)
│                  Conformance/ (ConformanceTestCase AnthropicConformanceTest fixtures/anthropic/*.json)
│                  Mcp/ (McpConformanceTest fixtures/mcp/*.json)
└── composer.json phpunit.xml.dist phpstan.neon.dist .gitignore .gitattributes README.md CHANGELOG.md LICENSE
```

## 5. Public API (v0.1)

Public unless marked `@internal`. In 0.x a minor release may break the API (with a "Backwards compatibility" section
in the CHANGELOG); patch releases never do; v1.0 freezes it. Bodies are omitted; array shapes are in the comments.

### 5.1 Messages and parts (`Ldkafka\Ai\Message`)

```php
enum Role: string { case User = 'user'; case Assistant = 'assistant'; }                 // who speaks
interface Part { public function toArray(): array; }      // a content block; toArray() = neutral storage form; closed set

final readonly class Text implements Part { public function __construct(public string $text) }   // plain text
final readonly class Image implements Part {              // an image: exactly one of $data (base64) or $url; jpeg/png/gif/webp
    public function __construct(public string $mediaType, public ?string $data = null, public ?string $url = null)
    public static function fromFile(string $path): self;   public static function fromUrl(string $url, string $mediaType = ''): self;
}
final readonly class Document implements Part {           // a PDF: exactly one of $data (base64) or $url
    public function __construct(public ?string $data = null, public ?string $url = null, public ?string $title = null)
    public static function fromFile(string $path, ?string $title = null): self;   public static function fromUrl(string $url, ?string $title = null): self;
}
final readonly class ToolCall implements Part {           // a call the model made; the JSON text is kept, so {} stays {}
    public function __construct(public string $id, public string $name, public string $argumentsJson = '{}')
    public static function of(string $id, string $name, array $arguments): self;   public function arguments(): array;
}
final readonly class ToolResult implements Part {         // the answer to one call; $content: list<Text|Image|Document>
    public function __construct(public string $callId, public string $name, public array $content, public bool $isError = false)
    public static function text(string $callId, string $name, string $text, bool $isError = false): self;
}   // the tool name is carried because Gemini's function response needs it
// Model reasoning; $opaque = the provider's own fields (Anthropic: signature or redacted data), echoed to it only.
final readonly class Thinking implements Part { public function __construct(public string $text, public string $provider, public array $opaque = []) }
// A block the core does not model (e.g. a server-tool result enabled through providerOptions), echoed to its provider only.
final readonly class ProviderPart implements Part { public function __construct(public string $provider, public string $json) }   // $json must be a JSON object

final readonly class Message {                            // one turn: a role and its parts
    public function __construct(public Role $role, public array $parts)   // list<Part>; ToolCall and Thinking only in assistant,
    public static function user(string|Part ...$parts): self;             // ToolResult only in user messages,
    public static function assistant(string|Part ...$parts): self;        // else \InvalidArgumentException
    public function text(): string;   public function toolCalls(): array; // Text parts joined; list<ToolCall>
    public function toArray(): array;   public static function fromArray(array $data): self;   // ['role', 'parts']
}
```

### 5.2 Request, response, usage (`Ldkafka\Ai`)

```php
final readonly class Request {                            // everything one model call needs; immutable
    public function __construct(
        public array $messages = [], public string $model = '',          // list<Message>; a catalog name, '' = default
        public ?string $system = null, public array $tools = [],         // tools: list<ToolSpec>
        public ?ToolChoice $toolChoice = null, public ?ResponseFormat $responseFormat = null,
        public ?int $maxTokens = null, public ?float $temperature = null, // maxTokens null = the model's configured value
        public array $stop = [], public array $metadata = [],             // list<string>; array<string, string>, advisory
        public array $cache = [], public array $requires = [],            // list<CacheHint>; list<Capability> (§5.3)
        public array $providerOptions = [],       // ['anthropic' => [...]], merged into that provider's payload last
    )
    public function withMessage(Message $message): self;   public function withMessages(array $messages): self;   // append; replace
    public function withModel(string $model): self;   public function withTools(array $tools): self;
    public function withToolChoice(?ToolChoice $toolChoice): self;      // NaN/INF temperature or options → \InvalidArgumentException
}
enum CacheScope: string { case Tools = 'tools'; case System = 'system'; case Messages = 'messages'; }   // where a breakpoint goes
// "Cache the prompt up to here." Advisory: ignored by models without promptCache unless the request requires it.
final readonly class CacheHint { public function __construct(public CacheScope $scope, public ?int $ttlSeconds = null) }
// Ask for JSON matching a schema. v0.1 maps it; hydration to typed objects and the repair retry arrive in v0.2.
final readonly class ResponseFormat { public static function jsonSchema(array $schema, string $name = 'response'): self; }

final readonly class Response {                           // one model answer
    public function __construct(
        public Message $message,                  // the assistant message: append it to the conversation as is
        public StopReason $stopReason, public string $rawStopReason, public Usage $usage,
        public string $provider, public string $model,                   // the provider model id that answered
        public ?string $id = null, public ?string $requestId = null,     // provider message id; request-id header
        public ?Cost $cost = null, public int $latencyMs = 0,            // null without a price; complete() wall time, retries included
        public array $raw = [],                   // decoded provider body; a debugging aid, not covered by semver
    )
    public function text(): string;   public function toolCalls(): array;
}
enum StopReason: string {                                 // why the model stopped, normalised
    case Complete = 'complete'; case MaxTokens = 'max_tokens'; case StopSequence = 'stop_sequence'; case ToolUse = 'tool_use';
    case Paused = 'paused';                       // the provider paused a long turn: send the message back to continue
    case Refused = 'refused'; case ContextLimit = 'context_limit';
    case Other = 'other';                         // unknown value: see $rawStopReason
}
final readonly class Usage {                              // tokens; input counters disjoint, output includes reasoning
    public function __construct(public int $inputTokens = 0, public int $outputTokens = 0, public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0, public int $reasoningTokens = 0, public array $details = [])   // e.g. cacheWrite1h
    public function promptTokens(): int;   public function add(self $other): self;   // input + cacheRead + cacheWrite
}
final readonly class Cost {                               // USD estimate at list price
    public function __construct(public float $input, public float $output, public float $cacheRead, public float $cacheWrite)
    public function total(): float;   public function add(self $other): self;
}
```

### 5.3 Models, capabilities, prices (`Ldkafka\Ai\Model`)

```php
enum Capability: string { case Tools = 'tools'; case Vision = 'vision'; case Pdf = 'pdf'; case JsonSchema = 'jsonSchema';
    case PromptCache = 'promptCache'; case ServerState = 'serverState'; case Thinking = 'thinking'; }   // what a model can do
final readonly class ModelProfile {                       // one configured model
    public function __construct(public string $name, public string $provider, public string $id,
        public array $capabilities,               // list<Capability>
        public int $maxContext, public int $maxOutput, public int $maxTokens,
        public ?Price $price = null, public array $options = [])    // options: provider payload defaults
    public function supports(Capability $capability): bool;
}
final class ModelCatalog {                                // the configured models by name
    public static function fromConfig(array $models, string $default): self;     // the params 'models' map
    public function get(string $name = ''): ModelProfile;   public function names(): array;   // unknown → ConfigurationException
}
final readonly class Price {                              // USD per MTok; $above: array<int, Price> used past that prompt size
    public function __construct(public float $input, public float $output, public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0, public ?float $cacheWrite1h = null, public array $above = [])
    public static function fromConfig(array $price): self;
}
final class CostCalculator { public function cost(Usage $usage, Price $price): Cost; }   // usage × price (§10)
```

Every model a request names must be in the catalog, which is where its capabilities and price live. Before any HTTP
call the Client derives what the request needs and throws `CapabilityException`, listing what the model lacks: tools,
a ToolCall or a ToolResult → `tools`; an Image anywhere, tool results included → `vision`; a Document → `pdf`; a
responseFormat → `jsonSchema`; plus every capability in `requires` (`promptCache`, `serverState`, `thinking`, …).

### 5.4 Client and pipeline

```php
final class Client {                                      // Ldkafka\Ai: one model call
    public const VERSION = '0.1.0';
    public function __construct(private ModelCatalog $models, private ProviderRegistry $providers,
        private array $middleware = [],           // list<MiddlewareInterface>, outermost first
        private CostCalculator $costs = new CostCalculator(), private ?LoggerInterface $logger = null)
    // Resolve the model, check capabilities, apply defaults, run middleware and provider, add cost and latency, log.
    public function complete(Request $request): Response;
    public function model(string $name = ''): ModelProfile;
}
// Ldkafka\Ai\Pipeline. PSR-15 shaped: v0.3 adds fallback, budgets, rate limiting and events here; v0.1 ships retry.
interface HandlerInterface { public function handle(Request $request, ModelProfile $model): Response; }   // the next step
interface MiddlewareInterface { public function process(Request $request, ModelProfile $model, HandlerInterface $next): Response; }
final readonly class RetryPolicy {                        // when to retry and how long to wait (§7.5)
    public function __construct(public int $maxRetries = 2, public int $baseDelayMs = 500, public int $maxDelayMs = 8000,
        public int $maxRetryAfterSeconds = 60)
    public function delayMs(int $retry, AiException $error): ?int;   // ms before retry $retry (1-based); null = do not retry
}
final class RetryMiddleware implements MiddlewareInterface {    // retries what the policy allows, logs each retry
    public function __construct(private RetryPolicy $policy = new RetryPolicy(), private ?LoggerInterface $logger = null,
        private ?\Closure $sleep = null)          // @internal test hook; default usleep
}
```

### 5.5 Providers and transport

```php
// Ldkafka\Ai\Provider. One attempt, no retry; throws ProviderException, TransportException, ConfigurationException.
interface ProviderInterface { public function send(Request $request, ModelProfile $model): Response; }
final class ProviderRegistry implements HandlerInterface {      // providers by name; handle() dispatches on $model->provider
    public function __construct(array $providers)                // array<string, ProviderInterface>
    public function get(string $name): ProviderInterface;        // unknown → ConfigurationException
}
final class AnthropicProvider implements ProviderInterface {     // Ldkafka\Ai\Provider\Anthropic: the Messages API
    public const NAME = 'anthropic';
    public function __construct(#[\SensitiveParameter] private string $apiKey, private TransportInterface $transport,
        private string $baseUrl = 'https://api.anthropic.com', private string $version = '2023-06-01',
        private array $betas = [], private AnthropicMapper $mapper = new AnthropicMapper(),   // betas → anthropic-beta
        private ?string $workspaceId = null)      // → anthropic-workspace-id when set
    public function __debugInfo(): array;         // the key shows as [redacted]
}
/** @internal Pure, no I/O: the unit the fixtures test. */
final class AnthropicMapper {
    public function toPayload(Request $request, ModelProfile $model): array;
    public function toResponse(string $json, ModelProfile $model, ?string $requestId = null): Response;
    public function toException(HttpResponse $response): ProviderException;
}
// Ldkafka\Ai\Http: send one HTTP request; throws TransportException.
// $request is #[\SensitiveParameter] here and in every implementation, so stack traces never show its headers.
interface TransportInterface { public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse; }
final readonly class HttpRequest { public function __construct(public string $method, public string $url, public array $headers = [], public string $body = '')
    public function __debugInfo(): array; }       // authorization, x-api-key and mcp-session-id values show as [redacted]
final readonly class HttpResponse { public function __construct(public int $status, public array $headers, public string $body)
    public function header(string $name): ?string; }            // lower-case header names
// Default: one reused handle (keep-alive), TLS verification on, TCP keep-alive on, no redirects. Seconds.
final class CurlTransport implements TransportInterface { public function __construct(private float $timeout = 600.0, private float $connectTimeout = 10.0) }
// Any PSR-18 client; timeouts and redirects are that client's own (Guzzle and Symfony follow redirects by default:
// turn that off). Only CurlTransport guarantees no redirects. Client exceptions count as failures before sending.
final class Psr18Transport implements TransportInterface {
    public function __construct(private ClientInterface $client, private RequestFactoryInterface $requests, private StreamFactoryInterface $streams) }
```

### 5.6 Errors (`Ldkafka\Ai\Exception`)

```php
class AiException extends \RuntimeException {}                 // base; messages never contain keys or prompts
final class ConfigurationException extends AiException {}     // unknown model or provider, missing API key, bad params
final class CapabilityException extends AiException { public function __construct(string $model, public readonly array $missing) }
final class TransportException extends AiException {         // DNS, TLS, connect, timeout, dropped connection
    public function __construct(string $message, bool $timeout = false, bool $afterSend = false, ?\Throwable $previous = null)
    public function isTimeout(): bool;   public function isAfterSend(): bool; }   // a timeout is also after send
final class ContextOverflowException extends AiException {}   // the newest turn alone exceeds the window budget
final class ToolException extends AiException {}              // thrown by a tool; its message is shown to the model
final class ProviderException extends AiException {          // the provider answered with an error
    public function __construct(string $message, public readonly string $provider, public readonly ErrorKind $kind,
        public readonly int $httpStatus, public readonly ?string $errorType = null,    // raw, e.g. overloaded_error
        public readonly ?string $requestId = null, public readonly ?float $retryAfter = null, ?\Throwable $previous = null)
}
enum ErrorKind: string {                                      // the failure, normalised across providers
    case InvalidRequest = 'invalid_request'; case Authentication = 'authentication'; case Permission = 'permission';
    case NotFound = 'not_found'; case RequestTooLarge = 'request_too_large'; case Billing = 'billing';
    case RateLimited = 'rate_limited'; case Overloaded = 'overloaded'; case Timeout = 'timeout';
    case Server = 'server'; case InvalidResponse = 'invalid_response'; case Unknown = 'unknown';
}
```

One exception class plus a kind enum keeps the API small (callers `match ($e->kind)`). Programming errors (an invalid
Message, a bad argument) throw `\InvalidArgumentException`.

### 5.7 Tools and Runner (`Ldkafka\Ai\Tool`)

```php
// A tool the model may call. The getters match YiiMcp\McpServer\Contract\McpToolInterface, so one class can implement
// both and the v1.0 bridge is a thin adapter; execution is handle() here, execute() there (their return shapes differ).
interface ToolInterface {
    public function getName(): string;            // ^[a-zA-Z0-9_-]{1,64}$, the strictest rule across providers
    public function getDescription(): string;
    public function getInputSchema(): array;      // JSON Schema of the arguments, type object
    public function handle(array $arguments): string|array;   // decoded, not schema-validated; returns a string or a
}                                                 // list<Text|Image|Document>; throw ToolException to tell the model
final readonly class ToolSpec {                           // a tool's definition as sent to the model
    public function __construct(public string $name, public string $description, public array $inputSchema)
    public static function fromTool(ToolInterface $tool): self;
}
enum ToolChoiceMode: string { case Auto = 'auto'; case None = 'none'; case Required = 'required'; case Tool = 'tool'; }
final readonly class ToolChoice {                         // whether and which tool the model must call
    public ToolChoiceMode $mode;   public ?string $name;   public bool $parallel;   public function isForced(): bool;
    public static function auto(bool $parallel = true): self;   public static function none(): self;
    public static function required(bool $parallel = true): self;   public static function tool(string $name): self;
}
final class ToolRegistry {                                // immutable; the allow-list of a run; bad or duplicate names → ConfigurationException
    public function __construct(iterable $tools = [])     // iterable<ToolInterface>
    public function with(ToolInterface $tool): self;   public function only(string ...$names): self;
    public function get(string $name): ?ToolInterface;   public function specs(): array;   // list<ToolSpec>
}
final class Runner {                                      // the bounded tool loop (§9)
    public function __construct(private Client $client, private ToolRegistry $tools = new ToolRegistry(),
        private ContextPolicyInterface $context = new FullReplay(), private int $maxSteps = 10,
        private ?float $maxCostUsd = null, private ?LoggerInterface $logger = null)
    public function withTools(ToolRegistry $tools): self;
    public function run(Request $request, ?\Closure $onMessage = null): RunResult;   // $onMessage(Message, ?Response) per appended message
}
enum RunStatus: string {                                  // why a run ended
    case Completed = 'completed'; case StepLimit = 'step_limit'; case BudgetExhausted = 'budget_exhausted'; }
final readonly class RunResult {                          // what a run produced
    public function __construct(public Response $response, public array $messages,   // every message the run appended
        public Usage $usage, public ?Cost $cost, public int $steps, public RunStatus $status)
    public function text(): string;               // the last response's text
}
```

### 5.8 Context (`Ldkafka\Ai\Context`)

```php
interface ConversationStoreInterface {                    // stored history, the source of truth
    // An assistant message may carry the Response it came from (model, usage, cost).
    public function append(string $conversationId, Message $message, ?Response $response = null): void;
    public function load(string $conversationId): array;  // list<Message> in order; [] for an unknown id
    public function delete(string $conversationId): void;
}
final class InMemoryConversationStore implements ConversationStoreInterface {}   // one process: tests, CLI, queue jobs
final class DbConversationStore implements ConversationStoreInterface {          // yiisoft/db, table in §8.3
    public function __construct(private ConnectionInterface $db, private string $table = '{{%ai_message}}') }
interface ContextPolicyInterface { public function apply(Request $request, ModelProfile $model): Request; }   // what history to send
final class FullReplay implements ContextPolicyInterface {}                      // everything (default)
final class TokenBudgetWindow implements ContextPolicyInterface {                // the newest whole turns within a budget
    public function __construct(private int $budgetTokens, private TokenEstimatorInterface $estimator = new HeuristicTokenEstimator(),
        private float $trimTo = 0.75) }
interface TokenEstimatorInterface { public function message(Message $message): int;   public function text(string $text): int; }
final class HeuristicTokenEstimator implements TokenEstimatorInterface {         // offline approximation (§8.1)
    public function __construct(private float $charsPerToken = 2.5, private int $imageTokens = 1600, private int $pdfPageTokens = 3000) }
final class Chat {                                        // persistent conversations: a store plus a Runner
    public function __construct(private Runner $runner, private ConversationStoreInterface $store)
    // $template carries model, system, cache hints, maxTokens and providerOptions; its messages must be empty.
    public function send(string $conversationId, Message|string $message, Request $template = new Request()): RunResult;
}
```

### 5.9 Usage sketch (the README quickstart)

```php
$params = (require 'vendor/ldkafka/yii3-ai/config/params.php')['ldkafka/yii3-ai'];   // plain PHP or Yii2
$client = new Client(
    ModelCatalog::fromConfig($params['models'], 'claude-opus-5-5'),
    new ProviderRegistry(['anthropic' => new AnthropicProvider((string) getenv('ANTHROPIC_API_KEY'), new CurlTransport())]),
    [new RetryMiddleware()],
);
$answer = $client->complete(new Request([Message::user('Summarise: …')], model: 'claude-haiku-5-5'));
echo $answer->text(), sprintf(' ($%.5f)', $answer->cost?->total());

$runner = new Runner($client, new ToolRegistry([new BookingLookupTool($bookings)]), maxSteps: 6, maxCostUsd: 0.25);
$result = $runner->run(new Request([Message::user('Where is booking 1234?')], system: 'You are Aura support.'));

$result = $chat->send('ticket-42', 'Can I change the dates?');   // Yii3: Chat is autowired from config
```

**Yii2 (Aura)** builds `Client`, `Runner` and `Chat` in a Yii2 container definition from the same params array, and
implements `ConversationStoreInterface` with `yii\db\Query` on the table in §8.3 (created by a Yii2 migration).

### 5.10 Remote MCP tools (`Ldkafka\Ai\Mcp`)

Added by the owner's decision 11 (§17). A client for tools on a **remote** MCP server, so a Runner can offer them to a
model. The first consumer is an internal watch app that calls tools on the owner's own MCP servers (yii3-mcp-server
behind nginx, an IP allow-list and a bearer token).

```php
final class McpHttpClient {                               // JSON-RPC 2.0 over MCP Streamable HTTP: one POST per message
    public const PROTOCOL_VERSION = '2025-11-25';         // proposed in initialize
    public const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];   // accepted in the server's answer
    public function __construct(private string $url, private TransportInterface $transport,
        #[\SensitiveParameter] private string $token = '',               // '' = no Authorization header
        private string $userAgent = 'ldkafka-yii3-ai/0.1.0',             // set your own (rule 2)
        private string $clientName = 'ldkafka-yii3-ai', private ?LoggerInterface $logger = null)
    public function initialize(): array;          // the InitializeResult; runs once, lazily, before the first call
    public function listTools(): array;           // list<array<string, mixed>>: every page, nextCursor followed
    public function callTool(string $name, array $arguments): array;      // the CallToolResult
    public function protocolVersion(): ?string;   public function sessionId(): ?string;   // null before initialize
    public function close(): void;                // DELETE the session when the server issued one; any answer accepted
}
final class RemoteMcpTool implements ToolInterface {      // one remote tool; handle() calls tools/call
    public function __construct(private McpHttpClient $client, private string $name, private string $description,
        private array $inputSchema)               // empty JSON objects inside stay \stdClass, so {} is re-sent as {}
    public static function fromDefinition(McpHttpClient $client, array $definition): self;   // one tools/list entry
}
final class RemoteMcpToolSource {                         // a server's tools, optionally allow-listed by name
    public function __construct(private McpHttpClient $client, private array $allow = [],   // list<string>; [] = all
        private ?LoggerInterface $logger = null)
    public function tools(): array;               // list<RemoteMcpTool>; one tools/list per call, no cache
    public function registry(ToolRegistry $base = new ToolRegistry()): ToolRegistry;   // $base plus these tools
}
// Ldkafka\Ai\Exception: the server or the transport failed; never carries the token, arguments or results.
final class McpException extends AiException {
    public function __construct(string $message, public readonly ?int $httpStatus = null,
        public readonly ?int $rpcCode = null, ?\Throwable $previous = null) }
```

**Protocol versions.** The client speaks the handshake-based revisions. It proposes `2025-11-25` and accepts the
server's answer when it is in `SUPPORTED_VERSIONS`; yii3-mcp-server 1.x answers `2025-06-18`. Any other answer is a
`McpException`. `2024-11-05` is left out: Streamable HTTP did not exist in that revision. The current revision,
`2026-07-28`, drops `initialize` for per-request `_meta` and is not spoken in v0.1: a server that supports both eras
answers `initialize` as usual, and a server that supports only `2026-07-28` answers 400, which surfaces as a
`McpException` naming the status. Speaking the stateless revision is a later item.

**Rules**
1. Handshake: `initialize` with `{protocolVersion, capabilities: {}, clientInfo: {name, version}}`, then the
   `notifications/initialized` notification (the server answers 202). The session id comes from the
   `Mcp-Session-Id` response header when the server sets one (yii3-mcp-server sets none).
2. Headers on every POST: `content-type: application/json`, `accept: application/json, text/event-stream`,
   `user-agent`, `authorization: Bearer <token>` when a token is set, `mcp-session-id` once issued, and
   `mcp-protocol-version` with the negotiated version on every request **after** `initialize`, never on it
   (yii3-mcp-server answers 400 to a version header it does not support, and the proposal may be newer than it knows).
   The User-Agent is configurable because the owner's nginx bans library default agents; the README tells callers to
   set their own.
3. Responses: `application/json` holds one message, or a batch from which the response with the request's id is
   taken. `text/event-stream`: events are split on blank lines, each event's `data:` lines are joined, and the
   JSON-RPC response with the request's id is taken; server requests and notifications on the stream are ignored.
   A request answered without its response is a `McpException`.
4. Retries: a 404 to a request that carried a session id means the session ended; the client forgets it,
   re-initializes once and resends once (the specification's MUST). Nothing else is retried: no backoff, no retry
   on 401, 403, 5xx or a timeout. The owner's nginx adds ban points per IP for each 403 and bans after three, so a
   client that loops on 403 bans its own host.
5. Errors: a non-2xx answer → `McpException` with `httpStatus`, the method and, when the body is a JSON-RPC error,
   its message (≤ 300 characters). A JSON-RPC error → `McpException` with `rpcCode` and the server's message. A
   body that is not JSON → `McpException`.
6. `tools/list` sends `cursor` on follow-up pages until no `nextCursor` comes back. A cursor seen before or more than
   `MAX_PAGES` (100) pages → `McpException`. Results are decoded with empty JSON objects, and objects whose keys are
   `0, 1, 2, ...`, kept as objects, so `"properties": {}` or `{"0": ...}` reaches the model provider unchanged.
7. `tools/call` sends `{name, arguments}`; empty arguments go as `{}`.
8. `RemoteMcpTool::handle()` maps the result content: `text` → Text; `image` → Image (base64 with its `mimeType`;
   a type other than jpeg, png, gif or webp → a Text note; data that is not valid base64 → `ToolException`); an embedded `resource` with `text` → Text;
   `resource_link` → a Text with its uri; any other type → a Text note `[<type> content omitted]`. No content but
   `structuredContent` → its JSON as one Text. When every part is Text, they are returned as one string joined by
   newlines, else as a list. `isError: true` → `ToolException` with the server's text, which reaches the model
   (§9 rule 6). A JSON-RPC `-32602` (yii3-mcp-server's argument check) → `ToolException` with the server's message,
   so the model can fix its arguments. Any other `McpException` propagates and the Runner reports "Tool X failed"
   to the model and logs the exception locally.
9. `RemoteMcpToolSource` skips names that break the `ToolInterface` name rule (`^[a-zA-Z0-9_-]{1,64}$`) and logs a
   warning; an allow-listed name the server does not list is also logged as a warning.
10. Logging: debug per HTTP exchange (method, status, milliseconds). Never the token, arguments or results.
    `__debugInfo()` shows the token and the session id as `[redacted]`. Arguments that cannot be encoded as JSON
    → `McpException` before any request.

```php
$mcp = new McpHttpClient('https://admin.example.com/mcp', new CurlTransport(timeout: 60.0),
    (string) getenv('WATCH_MCP_TOKEN'), userAgent: 'example-watch/1.0');
$tools = (new RemoteMcpToolSource($mcp, allow: ['prod_check', 'app_logs']))->registry();
$result = (new Runner($client, $tools, maxSteps: 8))->run(new Request([Message::user('What changed since 06:00 UTC?')]));
```

**Tests**: `tests/Mcp/McpConformanceTest` replays hand-written exchanges (`tests/Mcp/fixtures/*.json`, in the shape of
§12) through `FixtureTransport`: the handshake against a yii3-mcp-server-shaped server (no session, `2025-06-18`), a
session server (`Mcp-Session-Id`, 404 then re-initialize), SSE answers, cursor pages, `isError`, `-32602`, 401/403
without retry, an unsupported protocol version, and image content.

## 6. Config and DI

`config/params.php`, the defaults (apps override single keys; yiisoft/config merges recursively):

```php
return ['ldkafka/yii3-ai' => [
    'defaultModel' => 'claude-opus-5-5',
    'providers' => ['anthropic' => [
        'apiKey' => '',                                   // '' = getenv('ANTHROPIC_API_KEY'); never commit a key
        'baseUrl' => 'https://api.anthropic.com', 'version' => '2023-06-01', 'betas' => [],
    ]],
    'models' => [                                         // keys = the names used in Request::$model
        'claude-opus-5-5' => [
            'provider' => 'anthropic', 'id' => 'claude-opus-5-5',
            'capabilities' => ['tools', 'vision', 'pdf', 'jsonSchema', 'promptCache', 'thinking'],
            'maxContext' => 1_000_000, 'maxOutput' => 128_000, 'maxTokens' => 16_000,
            'price' => ['input' => 4.00, 'output' => 20.00, 'cacheRead' => 0.20, 'cacheWrite' => 5.00, 'cacheWrite1h' => 8.00],
            'options' => [],                              // payload defaults, e.g. ['output_config' => ['effort' => 'high']]
        ],
        // claude-sonnet-5-5, claude-haiku-5-5, claude-fable-5-1: same shape, values from §7.1 (USD per MTok, 12 Oct 2026).
        // Haiku adds 'above' => [100_000 => ['input' => 0.50, 'output' => 2.50, 'cacheRead' => 0.05, 'cacheWrite' => 0.625, 'cacheWrite1h' => 1.00]].
    ],
    'http' => ['timeout' => 600, 'connectTimeout' => 10],     // seconds per attempt; 600 s is the official SDKs' limit
    'retry' => ['maxRetries' => 2, 'baseDelayMs' => 500, 'maxDelayMs' => 8_000, 'maxRetryAfterSeconds' => 60],
    'runner' => ['maxSteps' => 10, 'maxCostUsd' => null],
    'context' => ['policy' => 'replay', 'budgetTokens' => 100_000],   // policy: 'replay' | 'window'
    'store' => ['type' => 'memory', 'table' => '{{%ai_message}}'],    // type: 'memory' | 'db'
]];
```

`config/di.php` (yiisoft/definitions; the logger is `Reference::optional(LoggerInterface::class)`, as in the template):

| Id | Definition |
|---|---|
| `TransportInterface` | `CurlTransport(timeout, connectTimeout)` |
| `AnthropicProvider` | `apiKey` (params, else `ANTHROPIC_API_KEY`), `Reference::to(TransportInterface)`, `baseUrl`, `version`, `betas` |
| `ProviderRegistry` | `['anthropic' => Reference::to(AnthropicProvider)]` |
| `ModelCatalog` | `ModelCatalog::fromConfig(models, defaultModel)` |
| `RetryPolicy`, `RetryMiddleware` | from `retry`, optional logger |
| `Client` | `middleware: [Reference::to(RetryMiddleware)]`, optional logger |
| `ToolRegistry` | empty; the app redefines it with its tools |
| `ContextPolicyInterface` | `FullReplay`, or `TokenBudgetWindow(budgetTokens)` when `policy` is `window` |
| `Runner` | `maxSteps`, `maxCostUsd`, optional logger |
| `ConversationStoreInterface` | `InMemoryConversationStore`, or `DbConversationStore(Reference::to(ConnectionInterface), table)` when `type` is `db` |
| `Chat` | autowired |

A missing API key fails at the first call (`ConfigurationException`), not at boot. The migration is not registered
automatically, so no table appears uninvited: an app that uses the DB store adds `'Ldkafka\\Ai\\Migration'` to
`params['yiisoft/db-migration']['sourceNamespaces']` and runs `yii migrate:up`.

## 7. Anthropic adapter (Messages API)

`POST {baseUrl}/v1/messages`, non-streaming. Request headers: `authorization: Bearer <key>` (§17; the documented
primary, `x-api-key` is the legacy fallback), `anthropic-version: 2023-06-01`,
`content-type: application/json`, `accept: application/json`, `user-agent: ldkafka-yii3-ai/0.1.0`, and
`anthropic-beta` (comma-joined) when the provider config or `providerOptions['anthropic']['betas']` lists any; that key
is moved to the header, never sent in the body. `anthropic-workspace-id` when `workspaceId` is set (params
`providers.anthropic.workspaceId`; required with a multi-workspace key). A request that cannot be encoded as JSON →
`ConfigurationException` before any HTTP call, so no `JsonException` leaves `Client::complete()`. Response headers read: `request-id`, `retry-after` (seconds).

### 7.1 Models (the defaults shipped in params)

| Id | Context / max output | Thinking | Default effort | Forced `tool_choice` | $/MTok: input · output · cache read · 5m write · 1h write |
|---|---|---|---|---|---|
| `claude-opus-5-5` | 1M / 128K | adaptive, always on | medium | 400 | 4 · 20 · 0.20 · 5 · 8 |
| `claude-sonnet-5-5` | 1M / 128K | adaptive (off = `between_tools`) | high | 400 | 2 · 10 · 0.10 · 2.50 · 4 |
| `claude-haiku-5-5` | 1M / 128K | adaptive | medium | accepted | 0.10 · 0.50 · 0.01 · 0.125 · 0.20; prompt > 100K: 0.50 · 2.50 · 0.05 · 0.625 · 1 |
| `claude-fable-5-1` | 1M / 128K | adaptive, always on | high | 400 | 10 · 50 · 0.25 · 12.50 · 20 |

All four declare `tools vision pdf jsonSchema promptCache thinking`; `serverState` is false (Anthropic keeps no
conversation state). v0.1 sends no `thinking` field, so every model runs adaptive thinking with the default display
(omitted: empty text, signature kept). Effort, display or the refusal `fallbacks` parameter are set per model through
`options`, plus `betas` where a beta header is needed.

### 7.2 Request mapping

| Ours | Anthropic | Rule |
|---|---|---|
| `ModelProfile::$id` | `model` | |
| `system` | `system` | a string; an array with one text block when a System cache hint applies |
| `messages[]` | `messages[]` `{role, content[]}` | user / assistant |
| `Text` | `{type: text, text}` | empty Text parts are skipped (the API rejects empty text) |
| `Image` | `{type: image, source: {type: base64, media_type, data}}` or `{source: {type: url, url}}` | |
| `Document` | `{type: document, source: {type: base64, media_type: application/pdf, data}}` or `{type: url}`; `title` | |
| `ToolCall` | `{type: tool_use, id, name, input}` | `input` decoded from `argumentsJson` as an object |
| `ToolResult` | `{type: tool_result, tool_use_id, content, is_error}` | one Text → a string, else blocks; placed first in the user message (API rule); `is_error` only when true |
| `Thinking` (anthropic) | `{type: thinking, thinking, signature}` or `{type: redacted_thinking, data}` | verbatim, in place; another provider's Thinking is dropped |
| `ProviderPart` (anthropic) | the raw block | verbatim; another provider's block is dropped |
| `tools[]` (`ToolSpec`) | `tools[]` `{name, description, input_schema}` | |
| `ToolChoice` auto / none / required / tool(name); `parallel: false` | `tool_choice` `{type: auto \| none \| any \| tool, name}`; `disable_parallel_tool_use: true` | sent as is: Opus 5.5, Sonnet 5.5 and Fable 5.1 answer 400 to `any` and `tool`, which surfaces |
| `ResponseFormat::jsonSchema` | `output_config.format` `{type: json_schema, schema}` | the API's schema limits apply (`additionalProperties: false`, …) |
| `maxTokens` | `max_tokens` (required) | null → the model's `maxTokens` |
| `temperature` | `temperature` | sent as is; Claude 5 models accept only 1.0 and answer 400 otherwise |
| `stop` | `stop_sequences` | |
| `metadata['user_id']` | `metadata.user_id` | the only key Anthropic accepts; other keys are not sent |
| `CacheHint` Tools / System / Messages | `cache_control: {type: ephemeral}` on the last tool / on the system block / at top level (automatic caching) | ttl null or 300 → the 5m default, 3600 → `"1h"`, other → ConfigurationException; a 1h hint after a 5m one in tools → system → messages order → ConfigurationException (the API wants longer TTLs first); at most 3 of the 4 breakpoints; prompts under 512 tokens are not cached (no error) |
| model `options`, then `providerOptions['anthropic']` | merged into the body | maps merge recursively, lists are replaced |

### 7.3 Response mapping

| Anthropic | Ours |
|---|---|
| `content[]` | assistant Message, parts in order: `text` → Text, `thinking` / `redacted_thinking` → Thinking, `tool_use` → ToolCall (`argumentsJson` = `input` re-encoded), any other type → ProviderPart |
| `stop_reason` | `end_turn` → Complete, `max_tokens` → MaxTokens, `stop_sequence` → StopSequence, `tool_use` → ToolUse, `pause_turn` → Paused, `refusal` → Refused, `model_context_window_exceeded` → ContextLimit, else Other; the raw value is kept |
| `usage.input_tokens` · `cache_read_input_tokens` · `cache_creation_input_tokens` | `inputTokens` · `cacheReadTokens` · `cacheWriteTokens` (null → 0) |
| `usage.cache_creation.ephemeral_1h_input_tokens` | `details['cacheWrite1h']`, priced at the 1h rate |
| `usage.output_tokens` · `output_tokens_details.thinking_tokens` | `outputTokens` · `reasoningTokens` (a subset of output) |
| `id`, `model`, header `request-id` | `id`, `model`, `requestId` |
| `stop_details`, `container`, `service_tier`, … | `raw` only |

The body is decoded with JSON objects kept as objects, so tool inputs and unknown blocks replay unchanged; on the
Claude 5 models a changed earlier tool input would also invalidate the replayed thinking blocks after it.

### 7.4 Error model

The body is `{"type": "error", "error": {"type", "message", "details"?}, "request_id"}`. A non-JSON body (a proxy page,
Cloudflare's 413) keeps its first 300 characters as the message.

| HTTP | `error.type` | ErrorKind | Retried |
|---|---|---|---|
| 400, 409, other 4xx | `invalid_request_error`, `conflict_error` | InvalidRequest | no |
| 401 / 403 / 404 | `authentication_error` / `permission_error` / `not_found_error` | Authentication / Permission / NotFound | no |
| 402 | `billing_error` | Billing | no |
| 408 | (not documented by Anthropic) | Timeout | yes |
| 413 | `request_too_large` (32 MB limit) | RequestTooLarge | no |
| 429 with `retry-after` | `rate_limit_error` | RateLimited | yes |
| 429 without `retry-after` | `rate_limit_error`; the spend cap carries `details.error_code: enforced_spend_limit_reached` | Billing for the spend cap, else RateLimited | no: the spend cap fails until next month |
| 500, 502, 503 | `api_error` | Server | yes |
| 504 | `timeout_error` | Timeout | yes |
| 529 | `overloaded_error` | Overloaded | yes |
| 2xx that is not JSON or lacks `content` | | InvalidResponse | no |
| connection failure before the request was sent (DNS, refused, TLS, connect timeout) | | TransportException | yes |
| read timeout | | TransportException (`isTimeout()`, `isAfterSend()`) | no: the call may have completed and been billed |
| connection dropped after the request was sent (curl 52 empty reply, 56 receive error, or pre-transfer done) | | TransportException (`isAfterSend()`) | no: same reason |

A message reads `Anthropic API error (HTTP 429 rate_limit_error): <provider message> [request req_…]`. It never
contains the key or the request body.

### 7.5 Retry policy (RetryMiddleware)

- At most `maxRetries` (2) retries, so 3 attempts, as in the official SDKs.
- Wait `retry-after` when present and ≤ `maxRetryAfterSeconds` (60). A longer `retry-after` is not waited out: the
  exception surfaces with `retryAfter` set, so a queue job can reschedule. Otherwise wait
  `min(maxDelayMs, baseDelayMs × 2^(n−1))` with jitter × [0.75, 1].
- Each retry logs a warning (kind, status, request id, wait). Timeouts are per attempt; the worst case is one full
  timeout plus the fast failures, retries and waits.

## 8. Context

### 8.1 Policies

The Runner applies its policy once, when a run starts. Messages appended during the run are never trimmed or edited,
so within a run the history is append-only, which keeps the prompt cache warm and thinking replay valid.

`FullReplay` (the default) sends the whole history. `TokenBudgetWindow(budgetTokens, estimator, trimTo = 0.75)`:
1. Budget = `min(budgetTokens, maxContext − maxTokens)`. Estimate = system + tool specs (JSON) + every message.
2. Within budget: the request is returned unchanged, thinking intact.
3. A turn starts at a user message with no ToolResult. Over budget, when the last message starts a turn: drop whole
   turns from the oldest until the estimate is ≤ `trimTo × budget`, never the last turn. Trimming to 75% leaves room
   for several turns before the cut moves again, so the cached prefix survives most turns.
4. Over budget while the last message is an open tool exchange (tool results): unchanged this time; it trims at the
   next turn. A tool call is never separated from its result.
5. Once anything is dropped, every Thinking part in the kept history is removed, and assistant messages left empty go.
   Anthropic rejects a replayed thinking block whose earlier history changed (enforced on the four Claude 5 models for
   accounts created from 31 Aug 2026), and its docs list removing all of them as valid. The cost: once the window is
   active the model no longer sees its earlier reasoning (summarising and compaction come in v0.3).
6. The last turn alone over budget: `ContextOverflowException`. A message is never truncated.

`HeuristicTokenEstimator`: text ÷ 2.5 characters per token (Anthropic: 1M tokens ≈ 2.5M characters on the current
tokenizer); tool arguments and results as their JSON text; 1,600 tokens per image; 3,000 per PDF page (pages counted
from `/Type /Page` objects, one page for a URL); thinking by its visible text. It is a cost knob, not a guarantee:
the provider's "prompt is too long" 400 remains the backstop.

### 8.2 Stores and Chat

The store is the source of truth; provider-side state is never relied on in v0.1. Conversation ids are the caller's
(1 to 64 characters). `Chat::send()`:
- stores the user message before the run, so it survives a failed call (a retry adds a second user message, which the
  API merges with the first);
- if the history ends with an assistant message whose tool calls have no results (the process died mid-run), first
  answers them with `isError` results "not executed: run interrupted", ahead of the new text in the same user message,
  because the API rejects a tool call without a result;
- stores each message the Runner appends as it is appended, with its Response (model, usage, cost).

Keep `system` and the tool set fixed per conversation: changing them also invalidates replayed thinking on Claude 5.

### 8.3 Table `{{%ai_message}}` (migration `Ldkafka\Ai\Migration\M261020000000CreateAiMessageTable`)

A yiisoft/db-migration `RevertibleMigrationInterface`, columns built with `$b->columnBuilder()`:

| Column | Type | Notes |
|---|---|---|
| `id` | `bigPrimaryKey()` | message order |
| `conversation_id` | `string(64)` not null | the caller's id |
| `role` | `string(16)` not null | `user` / `assistant` |
| `parts` | `json()` not null | `Message::toArray()['parts']`; tool arguments and provider blocks stay JSON strings inside |
| `provider`, `model`, `stop_reason` | `string(32)`, `string(128)`, `string(32)`, null | assistant rows |
| `input_tokens`, `output_tokens`, `cache_read_tokens`, `cache_write_tokens`, `reasoning_tokens` | `integer()` null | Usage |
| `cost_usd` | `decimal(16, 9)` null | `Cost::total()` |
| `created_at` | `datetime(3)` not null | UTC |

Index `idx-ai_message-conversation_id-id` on (`conversation_id`, `id`). Images and PDFs given as base64 are stored
inline; pass URLs to keep the table small. Spend per conversation is `SUM(cost_usd)`. No TTL in v0.1.

## 9. Runner loop rules

1. `maxCostUsd` set on a model without a price: `ConfigurationException` before any call.
2. A request with its own `tools` is rejected (`\InvalidArgumentException`): tools without handlers cannot run. The run
   offers exactly the registry's `specs()`, the same set on every step. A forced `toolChoice` (required or tool)
   applies to the first step only; later steps send `auto` with the same parallel setting, because forcing every
   step would end every run at `StepLimit`. `none` and `auto` are kept.
3. The context policy is applied once (§8.1).
4. Before every model call: `steps == maxSteps` → stop with `StepLimit`; summed cost ≥ `maxCostUsd` → stop with
   `BudgetExhausted`. The budget is soft: one call may overshoot it, bounded by `maxTokens`.
5. Call `Client::complete()`, add usage and cost, append the assistant message, call `onMessage`.
6. On `ToolUse`, run each ToolCall in order. Unknown or not allow-listed → error result "Tool X is not available".
   `ToolException` → error result with its message. Any other `Throwable` → error result "Tool X failed", the exception
   logged as a warning (it may hold internals, so it never goes to the provider). A string becomes one Text; a list
   must hold only Text, Image or Document, otherwise it is an error result.
7. All results of a step go into **one** user message, in call order; back to rule 4. Every call the model made is
   answered, so a stopped run leaves a valid history that a later run resumes.
8. `Paused`: resend with the assistant message last, as the API documents for `pause_turn`; it counts as a step.
9. Any other stop reason ends the run as `Completed`; the caller reads `$result->response->stopReason`. Tool calls in a
   MaxTokens response are never run.
10. v0.1 does not validate arguments against the schema: tools validate and throw `ToolException`, and the model retries.

## 10. Usage and cost accounting

- Every Response carries normalised Usage, a Cost when the model has a price, and `latencyMs`.
- `CostCalculator`: take the tier whose `above` key is exceeded by `promptTokens()` (Anthropic counts cache reads and
  writes in the prompt length), else the base price; then, all ÷ 10⁶,
  `input×input + cacheRead×cacheRead + (cacheWrite − w1h)×cacheWrite + w1h×cacheWrite1h + output×output`, with
  `w1h = details['cacheWrite1h']`. Reasoning tokens are already inside output and are not added again.
- Not modelled, so cost is a list-price estimate: the Batch discount, `inference_geo: us` (×1.1), fast mode,
  server-tool fees, Priority Tier. `raw` keeps the provider's own fields.
- `RunResult` sums usage and cost over its steps; `DbConversationStore` stores both per assistant message.
- Prices live in params, dated. A price change is a CHANGELOG line; the README links the provider's pricing page.

## 11. Logging and redaction

PSR-3, optional everywhere.
- `Client`, info, one line per call: provider, model, stop reason, the usage counters, cost, latency, attempts, request id.
- `Client`, warning, on failure: kind, HTTP status, raw error type, request id, latency, the provider message (≤ 300 chars).
- `RetryMiddleware`, warning per retry; `Runner`, warning per failing tool (class and message, local log only).
- Never logged: API keys and headers, prompts, message text, tool arguments and results, metadata values, base64 data.
  The key, the MCP token and the transport's `HttpRequest` are `#[\SensitiveParameter]`, so stack traces omit them;
  `__debugInfo()` on AnthropicProvider, McpHttpClient and HttpRequest shows `[redacted]` instead; transport errors
  name only the URL.
- v0.1 logs no content, so it has nothing to redact. Content logging with redaction rules is a later add-on.

## 12. Test plan

**Unit** (`tests/Unit`, one class per src class), notably: Part round trips with `{}` inside arguments; CostCalculator
(Haiku tier, 1h writes); RetryPolicy and RetryMiddleware (fake sleep); CapabilityException with zero transport requests;
Runner on a FakeProvider (limits, unknown tool, ToolException versus other Throwable, one results message, Paused);
TokenBudgetWindow (turns, open tool exchange, thinking strip, overflow); DbConversationStore on in-memory SQLite with the
real migration; CurlTransport against PHP's built-in server; `config/di.php` resolving Client, Runner and Chat in yiisoft/di.

**Conformance** (`tests/Conformance`). `ConformanceTestCase` holds the scenarios; each adapter's subclass supplies its
provider, a model profile and a fixture directory. A scenario runs through a real Client and RetryMiddleware (sleep
stubbed). Offline, `FixtureTransport` checks each outgoing request against the recorded one (method, path,
allow-listed headers, a JSON-equal body with objects kept as objects) and replays the recorded response.

| # | Scenario | Checks | # | Scenario | Checks |
|---|---|---|---|---|---|
| 1 | `text_reply` | Text, Complete, usage, model id | 11 | `tool_error_result` | `is_error` |
| 2 | `system_prompt` | system mapped | 12 | `tool_input_fidelity` | `{}` and nested `{}` re-sent unchanged |
| 3 | `multi_turn_replay` | three turns in order | 13 | `thinking_roundtrip` | thinking and redacted thinking echoed verbatim |
| 4 | `stop_max_tokens` | MaxTokens, the raw value | 14 | `prompt_cache` | hints → `cache_control`; cache read/write and the 1h split; cost |
| 5 | `stop_sequence` | StopSequence, `stop_sequences` | 15 | `json_schema_format` | `output_config.format` |
| 6 | `image_input` | base64 and URL sources | 16 | `refusal` | Refused; `stop_details` in raw |
| 7 | `pdf_input` | Document with a title | 17 | `unknown_block_preserved` | ProviderPart round trip |
| 8 | `tool_call` | ToolCall id, name, arguments; ToolUse | 18 | `error_retried` | 429 with `retry-after`, 529, 500: retried, then success; exhaustion surfaces |
| 9 | `tool_result_roundtrip` | results first, in one user message | 19 | `error_not_retried` | 400, 401, 413, spend-cap 429: one attempt; no key in the message |
| 10 | `parallel_tool_calls` | two calls, two results, in order | 20 | `capability_missing` | CapabilityException, zero HTTP requests |

**Fixture format**: one file per scenario, `tests/Conformance/fixtures/anthropic/<scenario>.json`:

```json
{ "scenario": "tool_call", "provider": "anthropic", "model": "claude-haiku-5-5",
  "recordedAt": "2026-10-20T09:14:03Z", "synthetic": false,
  "exchanges": [{
    "request":  { "method": "POST", "path": "/v1/messages",
                  "headers": { "anthropic-version": "2023-06-01", "content-type": "application/json" },
                  "body": { "model": "claude-haiku-5-5", "max_tokens": 1024, "tools": [], "messages": [] } },
    "response": { "status": 200, "headers": { "request-id": "req_011…" },
                  "body": { "id": "msg_01…", "type": "message", "role": "assistant", "content": [],
                            "stop_reason": "tool_use", "usage": { "input_tokens": 412, "output_tokens": 58 } } } }] }
```

Fixtures hold synthetic prompts only, tiny media (a 1×1 PNG, a one-page PDF) and allow-listed headers: no key, no
`authorization`, no customer data. Errors that cannot be triggered on demand (529, the spend cap) are hand-written
fixtures marked `"synthetic": true`.

**Live switch**: offline by default. `AI_LIVE=1` with `ANTHROPIC_API_KEY` runs the same scenarios against the real
API through `CurlTransport` (model `AI_LIVE_MODEL`, default `claude-haiku-5-5`; a full run costs about a cent). Request
equality is skipped, the structural checks stay, and scenarios whose fixture says `"live": false` are skipped (they
cannot be triggered on demand: `refusal`, `unknown_block_preserved`, `error_retried`, `error_not_retried`). `AI_RECORD=1` adds a
`RecordingTransport` that rewrites the fixtures. Live runs are local and manual, never in CI.

## 13. CI and static analysis

`.github/workflows/tests.yml`, on push to `main` and on pull requests, in the template's shape:
- `phpunit`: matrix PHP `8.3`, `8.4`, `8.5`; `shivammathur/setup-php@v2` with `curl, json, pdo_sqlite`, `coverage: none`;
  `composer install --no-interaction --no-progress --prefer-dist`; `vendor/bin/phpunit` (unit and offline conformance).
- `phpstan`: PHP 8.4, `vendor/bin/phpstan analyse --no-progress`.

**PHPStan level 9** (of 0–10 in PHPStan 2) on `src` and `tests`, no baseline. The main risk here is unchecked `mixed`
from `json_decode()` of provider bodies; level 9 forbids using `mixed` without narrowing it, which is exactly the mapper
discipline wanted. Level 10 adds implicit-mixed reports that land mostly on third-party signatures (yiisoft/db rows):
annotations without bugs caught. The code starts clean, so it needs no baseline.

## 14. Release checklist (v0.1.0)

1. Phase 1 merged on `main`; CI green on 8.3, 8.4 and 8.5; PHPStan clean, no baseline.
2. A live conformance run passes (the owner's key, Haiku 5.5); fixtures re-recorded if the API changed; params prices
   checked against the pricing page.
3. `composer validate --strict`; `.gitattributes` export-ignores `tests/`, `docs/`, `.github/`, `phpunit.xml.dist` and
   `phpstan.neon.dist`, so installs stay slim.
4. CHANGELOG `## [0.1.0] - YYYY-MM-DD` (Keep a Changelog, as in the template); `extra.branch-alias` `dev-main: 0.1.x-dev`;
   `Client::VERSION = '0.1.0'`; LICENSE BSD-3-Clause, "Copyright (c) 2026, ldkafka".
5. README: badges (tests: `github.com/ldkafka/yii3-ai/actions/workflows/tests.yml/badge.svg`; Packagist version and PHP
   version: `img.shields.io/packagist/v/ldkafka/yii3-ai.svg` and `…/php-v/…`; license BSD-3-Clause), install,
   quickstart (plain PHP and Yii3), the Yii2 note, config reference, models and capabilities, tools and the Runner,
   conversations and the migration, errors and retries, testing (offline and live).
6. Annotated tag `v0.1.0` on the release commit, never moved or re-tagged (Packagist rejected the re-tagged
   yii3-mcp-server 1.1.x). No AI attribution trailers in commits or tags.
7. A GitHub release with the CHANGELOG section as its notes.
8. Packagist: submit `https://github.com/ldkafka/yii3-ai` once, enable the GitHub hook, confirm 0.1.0 is listed.
9. Smoke test: `composer require ldkafka/yii3-ai:^0.1` in a fresh `yiisoft/app`, one live call through the container.

## 15. Later phases (not designed here)

- **v0.2**: an OpenAI Responses adapter, one OpenAI-compatible adapter (Mistral, Groq, OpenRouter, DeepSeek, Ollama,
  vLLM) and a Gemini adapter, each with its fixtures; streaming (incremental curl reads, stream events, Anthropic SSE);
  structured output (JSON-schema answers hydrated to typed objects, one repair retry); optimistic concurrency on
  `ConversationStoreInterface::append()` (an expected last message id), because v0.1 `Chat` assumes one writer per
  conversation and concurrent sends on one id can interleave rows.
- **v0.3**: provider-native context strategies chosen per model (Anthropic cache breakpoints placed automatically,
  OpenAI `previous_response_id`, Gemini `cachedContents`); cross-call budgets; the fallback chain.
- **v1.0**: API freeze; the MCP bridge for local tools (`ToolInterface` ↔ `McpToolInterface`; MCP `text`/`image`
  content ↔ Text/Image; `isError` ↔ ToolException). Remote MCP tools arrived early, in v0.1 (§5.10); the stateless
  MCP revision `2026-07-28` is not scheduled yet.
- **Vision items** (slots decided in §17): embeddings in v0.2 (Anthropic has no embeddings endpoint);
  the summarise-older-turns policy, the long-term MemoryStore (facts by key, tag and recency, optionally a tool), rate
  limiting, PSR-14 events and content logging with redaction in v0.3.

## 16. Lessons carried over

From **yii2-google-gemini** v2.0.0. Kept: REST only, no SDK; explicit client-side versus provider-side context modes
(now ContextPolicy, and provider-native strategies in v0.3); text, finish-reason and usage helpers (now a typed
Response); counting tokens before expensive calls (now TokenEstimator). Avoided: `{ok, status, data, error}` arrays
and swallowed `Throwable` (→ typed exceptions); `extractText()` reading only `parts[0]` (→ every part, typed); chat
history in a TTL cache holding only the first text part (→ the store is the source of truth and keeps every part);
`chatServer()` silently creating a server cache from a placeholder prompt (→ no silent fallback); "streaming" that
buffered the whole body (→ v0.2 streams through curl's write callback); API guesses shipped untested, such as the Files
upload flow and the doubled `files/` prefix (→ fixtures recorded from the real API); `Yii::$app->get()` service
location (→ constructor injection); retries left to the reader (→ built in); placeholder composer metadata, no tests.

From **yii3-google-cloud-logging**: the curl transport behind an interface with a fake in tests, params/di wiring,
exception messages free of key material, a `.gitignore` for key files. From **yii3-mcp-server**: empty JSON objects
must stay `{}` (it shipped that fix); tool failures become error results so the model can recover; tags are never rewritten.

## 17. Decided (owner, 12 Oct 2026)

1. **Namespace** `Ldkafka\Ai`: kept.
2. **PHP** `^8.3`; CI runs 8.3, 8.4 and 8.5.
3. **Auth header**: `Authorization: Bearer <key>` instead of `x-api-key` (§7 and the fixtures follow).
4. **Retries** as designed: no retry on a 429 without `retry-after` (the spend cap), none on a read timeout.
5. **Tool errors**: only `ToolException` text reaches the model; any other Throwable becomes "Tool X failed" and is
   logged locally.
6. **Unknown provider blocks** are kept opaque in `ProviderPart` and round-trip.
7. **Window versus thinking**: `TokenBudgetWindow` strips all earlier thinking once it trims (v0.1).
8. **Effort and thinking** settings go through model `options` and `providerOptions`; no typed `Request` field.
9. **Later-phase slots** as proposed in §15.
10. **Defaults**: the memory store, the migration opt-in, the Claude 5 prices dated in params.
11. **New in v0.1**: a small client for remote MCP tools (§5.10), first used by an internal watch app that calls
    tools on the owner's MCP servers behind an IP allow-list.

**Deviations found while building v0.1.0**
- `Ldkafka\Ai\Internal\Json` (`@internal`) holds the JSON flags and the decode that keeps empty objects as `{}`,
  shared by the mapper, the stores and the MCP client.
- `AnthropicMapper` (`@internal`) also has `betas(Request): list<string>`, so the provider can build the
  `anthropic-beta` header from the same rule that strips `betas` from the body.
- `AnthropicMapper` sends an empty or missing `properties` in a tool's input schema as `{}` (PHP arrays cannot tell
  `[]` from `{}`; the API rejects `"properties": []`). yii3-mcp-server applies the same rule.
- `Ldkafka\Ai\Context\ConversationId` (`@internal`) holds the 1-to-64 id rule both stores apply.
- `Client` counts provider attempts with a terminal handler (the log line's `attempts`), so no middleware needs to
  report them.
- `Runner` rejects `maxSteps < 1` and `maxCostUsd <= 0` with `\InvalidArgumentException`, so every run makes at least
  one call and `RunResult::$response` is never empty.
- `McpHttpClient` stops with `McpException` when a server repeats a `tools/list` cursor, instead of looping.
- The live-run skip rule is a fixture's `"live": false` flag (§12), not `"synthetic"`: every fixture is synthetic until
  the first recording, and the ones that cannot be triggered on demand stay skipped after it.
- Test support beyond §4: `QueueTransport`, `CallbackTool`, `ArrayLogger`, `RecordingConversationStore`,
  `RecordingPsr18Client`; `tests/Conformance/RecordingTransportTest` checks that a recording replays and holds no key.
- Duplicate cache hints for one scope throw `ConfigurationException`.
- Review fixes before release (12 Oct): the secrets in traces and dumps (§5.5, §11); `workspaceId` (§7); the cache TTL
  order (§7.2); the MCP cursor set and page cap (§5.10); JSON safety (`ProviderPart` must be a JSON object, NaN/INF
  rejected in `Request` and `ModelProfile` options, encoding failures wrapped); `isAfterSend()` (§7.4); numeric-key
  objects kept as objects (`Internal\Json`); MCP image base64 check; the forced tool choice on the first step only
  (§9); `ToolChoiceMode` enum; the Psr18Transport redirect note; Chat's one-writer rule (§15); `composer validate`
  in CI.
- Known, left as is: value objects declared `readonly` can hold an empty `\stdClass` (a schema's `{}`), which is
  mutable, so they are not deeply immutable. Nothing in the package mutates it.
- The conformance fixtures are hand-written from the documented shapes and marked `"synthetic": true` until the
  first live run with a key re-records them (`AI_RECORD=1`).

## Sources (fetched 12 Oct 2026, nothing saved)

- API (`https://platform.claude.com/docs/en/api/`): `messages.md` (Messages reference), `overview.md` (base URL,
  headers, size limits), `errors.md`, `rate-limits.md` (`retry-after`, the spend cap).
- Guides (`https://platform.claude.com/docs/en/build-with-claude/`): `handling-stop-reasons.md`, `prompt-caching.md`,
  `preserved-thinking.md`, `structured-outputs.md`; and
  `https://platform.claude.com/docs/en/agents-and-tools/tool-use/handle-tool-calls.md` (tool results).
- Models and prices: `https://platform.claude.com/docs/en/about-claude/models/overview.md`,
  `https://platform.claude.com/docs/en/about-claude/pricing.md`.
- yiisoft/db-migration: `https://github.com/yiisoft/db-migration` (README, `docs/guide/en/usage-with-yii-console.md`,
  `src/MigrationBuilder.php`, `composer.json`).
- MCP (for §5.10): `https://modelcontextprotocol.io/specification/versioning` (current revision `2026-07-28`, the
  handshake-based revisions up to `2025-11-25`), `https://modelcontextprotocol.io/specification/2025-11-25/basic/transports`
  (Streamable HTTP), `https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning` (backward
  compatibility), and the owner's `yii3-mcp-server` source (`McpHttpHandler`, `McpServer`, `ProtocolVersion`).
