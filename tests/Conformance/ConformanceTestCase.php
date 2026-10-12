<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Conformance;

use Ldkafka\Ai\CacheHint;
use Ldkafka\Ai\CacheScope;
use Ldkafka\Ai\Client;
use Ldkafka\Ai\Exception\CapabilityException;
use Ldkafka\Ai\Exception\ErrorKind;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Http\CurlTransport;
use Ldkafka\Ai\Http\TransportInterface;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Message\Document;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\ProviderPart;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Message\Thinking;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Model\Capability;
use Ldkafka\Ai\Model\ModelCatalog;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Pipeline\RetryMiddleware;
use Ldkafka\Ai\Pipeline\RetryPolicy;
use Ldkafka\Ai\Provider\ProviderInterface;
use Ldkafka\Ai\Provider\ProviderRegistry;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\ResponseFormat;
use Ldkafka\Ai\StopReason;
use Ldkafka\Ai\Tests\Support\FixtureTransport;
use Ldkafka\Ai\Tests\Support\RecordingTransport;
use Ldkafka\Ai\Tool\ToolChoice;
use Ldkafka\Ai\Tool\ToolInterface;
use Ldkafka\Ai\Tool\ToolRegistry;
use Ldkafka\Ai\Tool\ToolSpec;
use Ldkafka\Ai\Tool\Runner;
use Ldkafka\Ai\Tool\RunStatus;
use Ldkafka\Ai\Exception\ToolException;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function count;
use function getenv;
use function is_string;

/**
 * The 20 conformance scenarios every adapter passes.
 *
 * Offline (the default), each scenario replays its fixture through FixtureTransport, which checks
 * each outgoing request against the recorded one. With AI_LIVE=1 and the provider's key, the same
 * scenarios run against the real API through CurlTransport: request equality is skipped, the
 * structural checks stay, and fixtures marked `"live": false` (errors that cannot be triggered on
 * demand) are skipped. AI_RECORD=1 also rewrites the fixtures from the live exchanges.
 */
abstract class ConformanceTestCase extends TestCase
{
    /** A placeholder key; it must never appear in an exception message. */
    protected const FAKE_KEY = 'sk-test-0000-placeholder-key';

    /** A 1x1 red PNG. */
    protected const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';

    /** A public image for the URL source. */
    protected const IMAGE_URL = 'https://upload.wikimedia.org/wikipedia/commons/4/47/PNG_transparency_demonstration_1.png';

    /** A one-page PDF that says "Hello PDF". */
    protected const PDF_ONE_PAGE = 'JVBERi0xLjQKMSAwIG9iago8PC9UeXBlL0NhdGFsb2cvUGFnZXMgMiAwIFI+PgplbmRvYmoKMiAwIG9iago8PC9UeXBlL1BhZ2VzL0tpZHNbMyAwIFJdL0NvdW50IDE+PgplbmRvYmoKMyAwIG9iago8PC9UeXBlL1BhZ2UvUGFyZW50IDIgMCBSL01lZGlhQm94WzAgMCAyMDAgMjAwXS9Db250ZW50cyA0IDAgUi9SZXNvdXJjZXM8PC9Gb250PDwvRjE8PC9UeXBlL0ZvbnQvU3VidHlwZS9UeXBlMS9CYXNlRm9udC9IZWx2ZXRpY2E+Pj4+Pj4+PgplbmRvYmoKNCAwIG9iago8PC9MZW5ndGggNDA+PgpzdHJlYW0KQlQgL0YxIDI0IFRmIDIwIDEwMCBUZCAoSGVsbG8gUERGKSBUaiBFVAplbmRzdHJlYW0KZW5kb2JqCnhyZWYKMCA1CjAwMDAwMDAwMDAgNjU1MzUgZiAKMDAwMDAwMDAwOSAwMDAwMCBuIAowMDAwMDAwMDU0IDAwMDAwIG4gCjAwMDAwMDAxMDUgMDAwMDAgbiAKMDAwMDAwMDI1OCAwMDAwMCBuIAp0cmFpbGVyCjw8L1NpemUgNS9Sb290IDEgMCBSPj4Kc3RhcnR4cmVmCjM0NgolJUVPRgo=';

    /** @var list<int> Milliseconds the retry middleware would have slept. */
    protected array $sleeps = [];

    private FixtureTransport|RecordingTransport|null $transport = null;

    /** @var array<string, mixed> */
    private array $fixture = [];

    private string $scenario = '';

    /**
     * The adapter under test, on the given transport (live: with the real key).
     */
    abstract protected function provider(TransportInterface $transport): ProviderInterface;

    /**
     * The model profile used offline; its name is the catalog default.
     */
    abstract protected function profile(): ModelProfile;

    /**
     * The model profile used live.
     */
    abstract protected function liveProfile(): ModelProfile;

    /**
     * The directory with `<scenario>.json` fixtures.
     */
    abstract protected function fixtureDir(): string;

    /**
     * Whether a live run has what it needs (the key).
     */
    abstract protected function liveReady(): bool;

    public function testTextReply(): void
    {
        $client = $this->begin('text_reply');
        $response = $client->complete(new Request([Message::user('Say hello in one word.')], maxTokens: 1024));

        self::assertSame(StopReason::Complete, $response->stopReason);
        self::assertNotSame('', $response->text());
        self::assertGreaterThan(0, $response->usage->inputTokens);
        self::assertGreaterThan(0, $response->usage->outputTokens);
        self::assertNotSame('', $response->model);
        self::assertNotNull($response->id);
        self::assertNotNull($response->requestId);
        self::assertNotNull($response->cost);
        $sent = $this->requests()[0];
        self::assertStringStartsWith('Bearer ', $sent->headers['authorization'] ?? '');
        self::assertArrayNotHasKey('x-api-key', $sent->headers);
        if (!$this->live()) {
            self::assertSame('Hello!', $response->text());
            self::assertSame('claude-haiku-5-5', $response->model);
        }
        $this->finish();
    }

    public function testSystemPrompt(): void
    {
        $client = $this->begin('system_prompt');
        $response = $client->complete(new Request(
            [Message::user('Say thank you.')],
            system: 'You answer in French, in one short sentence.',
            maxTokens: 1024,
        ));

        self::assertSame(StopReason::Complete, $response->stopReason);
        self::assertNotSame('', $response->text());
        $this->finish();
    }

    public function testMultiTurnReplay(): void
    {
        $client = $this->begin('multi_turn_replay');
        $response = $client->complete(new Request([
            Message::user('My name is Ada.'),
            Message::assistant('Nice to meet you, Ada.'),
            Message::user('What is my name? Answer with the name only.'),
        ], maxTokens: 1024));

        self::assertSame(StopReason::Complete, $response->stopReason);
        self::assertStringContainsString('Ada', $response->text());
        $this->finish();
    }

    public function testStopMaxTokens(): void
    {
        $client = $this->begin('stop_max_tokens');
        $response = $client->complete(new Request([Message::user('Count from 1 to 200, separated by commas.')], maxTokens: 5));

        self::assertSame(StopReason::MaxTokens, $response->stopReason);
        self::assertSame('max_tokens', $response->rawStopReason);
        $this->finish();
    }

    public function testStopSequence(): void
    {
        $client = $this->begin('stop_sequence');
        $response = $client->complete(new Request(
            [Message::user('Count from 1 to 10, separated by spaces. Output only the numbers.')],
            maxTokens: 1024,
            stop: ['7'],
        ));

        self::assertSame(StopReason::StopSequence, $response->stopReason);
        self::assertSame('stop_sequence', $response->rawStopReason);
        self::assertStringNotContainsString('7', $response->text());
        $this->finish();
    }

    public function testImageInput(): void
    {
        $client = $this->begin('image_input');
        $response = $client->complete(new Request([Message::user(
            'Describe the two images in a few words.',
            new Image('image/png', self::PNG_1X1),
            Image::fromUrl(self::IMAGE_URL),
        )], maxTokens: 1024));

        self::assertSame(StopReason::Complete, $response->stopReason);
        self::assertNotSame('', $response->text());
        $this->finish();
    }

    public function testPdfInput(): void
    {
        $client = $this->begin('pdf_input');
        $response = $client->complete(new Request([Message::user(
            new Document(self::PDF_ONE_PAGE, null, 'Test page'),
            'What does the document say? Answer in one sentence.',
        )], maxTokens: 1024));

        self::assertSame(StopReason::Complete, $response->stopReason);
        self::assertNotSame('', $response->text());
        $this->finish();
    }

    public function testToolCall(): void
    {
        $client = $this->begin('tool_call');
        $response = $client->complete(new Request(
            [Message::user('What is the weather in Paris?')],
            tools: [self::weatherSpec()],
            toolChoice: ToolChoice::tool('get_weather'),
            maxTokens: 1024,
        ));

        self::assertSame(StopReason::ToolUse, $response->stopReason);
        $calls = $response->toolCalls();
        self::assertCount(1, $calls);
        self::assertNotSame('', $calls[0]->id);
        self::assertSame('get_weather', $calls[0]->name);
        self::assertArrayHasKey('city', $calls[0]->arguments());
        $this->finish();
    }

    public function testToolResultRoundtrip(): void
    {
        $client = $this->begin('tool_result_roundtrip');
        $question = Message::user('What is the weather in Paris?');
        $first = $client->complete(new Request(
            [$question],
            tools: [self::weatherSpec()],
            toolChoice: ToolChoice::tool('get_weather'),
            maxTokens: 1024,
        ));
        self::assertSame(StopReason::ToolUse, $first->stopReason);
        $call = $first->toolCalls()[0];

        // Text first on purpose: the adapter must put the tool result first.
        $answer = Message::user('Answer in one sentence.', ToolResult::text($call->id, $call->name, '18 degrees and sunny'));
        $second = $client->complete(new Request(
            [$question, $first->message, $answer],
            tools: [self::weatherSpec()],
            maxTokens: 1024,
        ));

        self::assertSame(StopReason::Complete, $second->stopReason);
        self::assertNotSame('', $second->text());
        $this->finish();
    }

    public function testParallelToolCalls(): void
    {
        $client = $this->begin('parallel_tool_calls');
        $tool = self::weatherTool(static fn (array $arguments): string => '18 degrees in ' . (is_string($city = $arguments['city'] ?? null) ? $city : '?'));
        $result = (new Runner($client, new ToolRegistry([$tool]), maxSteps: 4))->run(new Request(
            [Message::user('What is the weather in Paris and in Rome? Call get_weather once per city, both at once.')],
            maxTokens: 1024,
        ));

        self::assertSame(RunStatus::Completed, $result->status);
        $calls = $result->messages[0]->toolCalls();
        $results = array_values(array_filter($result->messages[1]->parts, static fn ($part): bool => $part instanceof ToolResult));
        self::assertCount(count($calls), $results);
        foreach ($calls as $index => $call) {
            $part = $results[$index];
            self::assertInstanceOf(ToolResult::class, $part);
            self::assertSame($call->id, $part->callId);
        }
        if (!$this->live()) {
            self::assertCount(2, $calls);
        }
        $this->finish();
    }

    public function testToolErrorResult(): void
    {
        $client = $this->begin('tool_error_result');
        $tool = self::weatherTool(static function (array $arguments): string {
            throw new ToolException('City not found: ' . (is_string($city = $arguments['city'] ?? null) ? $city : '?'));
        });
        $result = (new Runner($client, new ToolRegistry([$tool]), maxSteps: 3))->run(new Request(
            [Message::user('What is the weather in Atlantis? Use get_weather.')],
            maxTokens: 1024,
        ));

        self::assertGreaterThanOrEqual(2, count($result->messages));
        $results = $result->messages[1]->parts;
        self::assertInstanceOf(ToolResult::class, $results[0]);
        self::assertTrue($results[0]->isError);
        self::assertStringStartsWith('City not found', $results[0]->content[0] instanceof Text ? $results[0]->content[0]->text : '');
        $this->finish();
    }

    public function testToolInputFidelity(): void
    {
        $client = $this->begin('tool_input_fidelity');
        $search = new ToolSpec('search', 'Search records.', [
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string'], 'filters' => ['type' => 'object'], 'options' => ['type' => 'object'], 'tags' => ['type' => 'array']],
        ]);
        $response = $client->complete(new Request([
            Message::user('Run the saved search, then search for x.'),
            Message::assistant(new ToolCall('toolu_01Fidelity', 'search', '{"q":"saved","filters":{},"options":{"nested":{}},"tags":[]}')),
            Message::user(ToolResult::text('toolu_01Fidelity', 'search', '0 results')),
        ], tools: [$search], toolChoice: ToolChoice::tool('search'), maxTokens: 1024));

        self::assertSame(StopReason::ToolUse, $response->stopReason);
        $call = $response->toolCalls()[0];
        self::assertSame('search', $call->name);
        if (!$this->live()) {
            self::assertSame('{"q":"x","opts":{}}', $call->argumentsJson);
        }
        $this->finish();
    }

    public function testThinkingRoundtrip(): void
    {
        $client = $this->begin('thinking_roundtrip');
        $question = Message::user('What is the weather in Paris? Think first.');
        $first = $client->complete(new Request(
            [$question],
            tools: [self::weatherSpec()],
            toolChoice: ToolChoice::auto(),
            maxTokens: 2048,
        ));
        $thinking = array_values(array_filter($first->message->parts, static fn ($part): bool => $part instanceof Thinking));
        foreach ($thinking as $part) {
            self::assertInstanceOf(Thinking::class, $part);
            self::assertSame('anthropic', $part->provider);
        }
        if (!$this->live()) {
            self::assertCount(2, $thinking);
            self::assertInstanceOf(Thinking::class, $thinking[0]);
            self::assertInstanceOf(Thinking::class, $thinking[1]);
            self::assertSame('sig-abc123', $thinking[0]->opaque['signature'] ?? null);
            self::assertSame('redacted-xyz789', $thinking[1]->opaque['data'] ?? null);
        }

        $calls = $first->toolCalls();
        if ($calls !== []) {
            $results = [];
            foreach ($calls as $call) {
                $results[] = ToolResult::text($call->id, $call->name, '18 degrees and sunny');
            }
            $second = $client->complete(new Request(
                [$question, $first->message, Message::user(...$results)],
                tools: [self::weatherSpec()],
                maxTokens: 2048,
            ));
            self::assertSame(StopReason::Complete, $second->stopReason);
        }
        $this->finish();
    }

    public function testPromptCache(): void
    {
        $client = $this->begin('prompt_cache');
        $response = $client->complete(new Request(
            [Message::user('Summarise the policy in one sentence.')],
            system: 'You are a support agent. Policy: guests may cancel up to 14 days before arrival for a full refund.',
            tools: [self::weatherSpec()],
            maxTokens: 1024,
            cache: [
                new CacheHint(CacheScope::Tools, 3600),
                new CacheHint(CacheScope::System),
                new CacheHint(CacheScope::Messages),
            ],
        ));

        self::assertSame(StopReason::Complete, $response->stopReason);
        self::assertNotNull($response->cost);
        if (!$this->live()) {
            $usage = $response->usage;
            self::assertSame(20, $usage->inputTokens);
            self::assertSame(1800, $usage->cacheReadTokens);
            self::assertSame(2400, $usage->cacheWriteTokens);
            self::assertSame(['cacheWrite1h' => 2000], $usage->details);
            // Haiku 5.5: 20 x 0.10 + 1800 x 0.01 + 400 x 0.125 + 2000 x 0.20 + 50 x 0.50, per million.
            self::assertEqualsWithDelta(0.000495, $response->cost->total(), 1e-12);
            self::assertEqualsWithDelta(0.00045, $response->cost->cacheWrite, 1e-12);
        }
        $this->finish();
    }

    public function testJsonSchemaFormat(): void
    {
        $client = $this->begin('json_schema_format');
        $response = $client->complete(new Request(
            [Message::user('What is 2 + 2?')],
            responseFormat: ResponseFormat::jsonSchema([
                'type' => 'object',
                'properties' => ['answer' => ['type' => 'string']],
                'required' => ['answer'],
                'additionalProperties' => false,
            ]),
            maxTokens: 1024,
        ));

        self::assertSame(StopReason::Complete, $response->stopReason);
        $decoded = Json::decode($response->text());
        self::assertIsArray($decoded);
        self::assertArrayHasKey('answer', $decoded);
        $this->finish();
    }

    public function testRefusal(): void
    {
        $client = $this->begin('refusal');
        $response = $client->complete(new Request([Message::user('A request the model refuses.')], maxTokens: 1024));

        self::assertSame(StopReason::Refused, $response->stopReason);
        self::assertSame('refusal', $response->rawStopReason);
        self::assertArrayHasKey('stop_details', $response->raw);
        $this->finish();
    }

    public function testUnknownBlockPreserved(): void
    {
        $client = $this->begin('unknown_block_preserved');
        $question = Message::user('Search the web for the Aura v5 release date.');
        $first = $client->complete(new Request([$question], maxTokens: 1024));
        $provider = array_values(array_filter($first->message->parts, static fn ($part): bool => $part instanceof ProviderPart));
        self::assertCount(2, $provider);

        $second = $client->complete(new Request([$question, $first->message, Message::user('Thanks. Summarise in one line.')], maxTokens: 1024));
        self::assertSame(StopReason::Complete, $second->stopReason);
        $this->finish();
    }

    public function testErrorRetried(): void
    {
        $client = $this->begin('error_retried');

        // 429 with retry-after, then 529, then success.
        $response = $client->complete(new Request([Message::user('Say hello.')], maxTokens: 1024));
        self::assertSame(StopReason::Complete, $response->stopReason);
        self::assertCount(2, $this->sleeps);
        self::assertSame(1000, $this->sleeps[0]);
        self::assertGreaterThanOrEqual(750, $this->sleeps[1]);
        self::assertLessThanOrEqual(1000, $this->sleeps[1]);

        // 500 three times: the retries are exhausted and the last error surfaces.
        $this->sleeps = [];
        try {
            $client->complete(new Request([Message::user('Say hello.')], maxTokens: 1024));
            self::fail('The exhausted retries must surface.');
        } catch (ProviderException $e) {
            self::assertSame(ErrorKind::Server, $e->kind);
            self::assertSame(500, $e->httpStatus);
            self::assertSame('api_error', $e->errorType);
            self::assertCount(2, $this->sleeps);
        }
        self::assertCount(6, $this->requests());
        $this->finish();
    }

    public function testErrorNotRetried(): void
    {
        $client = $this->begin('error_not_retried');
        $expected = [
            [ErrorKind::InvalidRequest, 400, 'invalid_request_error'],
            [ErrorKind::Authentication, 401, 'authentication_error'],
            [ErrorKind::RequestTooLarge, 413, null],
            [ErrorKind::Billing, 429, 'rate_limit_error'],
        ];
        foreach ($expected as $index => [$kind, $status, $type]) {
            try {
                $client->complete(new Request([Message::user('Say hello.')], maxTokens: 1024));
                self::fail('Expected an error for case ' . $index);
            } catch (ProviderException $e) {
                self::assertSame($kind, $e->kind);
                self::assertSame($status, $e->httpStatus);
                self::assertSame($type, $e->errorType);
                self::assertNull($e->retryAfter);
                self::assertStringNotContainsString(self::FAKE_KEY, $e->getMessage());
                self::assertStringNotContainsString('Say hello', $e->getMessage());
            }
            self::assertCount($index + 1, $this->requests(), 'One attempt per error');
        }
        self::assertSame([], $this->sleeps);
        $this->finish();
    }

    public function testCapabilityMissing(): void
    {
        $this->begin('capability_missing');
        $profile = $this->live() ? $this->liveProfile() : $this->profile();
        $blind = new ModelProfile(
            $profile->name,
            $profile->provider,
            $profile->id,
            array_values(array_filter($profile->capabilities, static fn (Capability $c): bool => $c !== Capability::Vision)),
            $profile->maxContext,
            $profile->maxOutput,
            $profile->maxTokens,
            $profile->price,
        );
        $client = $this->client($blind, $this->transport());

        try {
            $client->complete(new Request([Message::user(new ToolResult('x', 'y', [new Image('image/png', self::PNG_1X1)]))]));
            self::fail('A missing capability must throw.');
        } catch (CapabilityException $e) {
            self::assertSame([Capability::Vision], $e->missing);
        }
        self::assertSame([], $this->requests());
        $this->finish();
    }

    /**
     * Start a scenario: load its fixture and build a client on the right transport.
     */
    protected function begin(string $scenario): Client
    {
        $this->scenario = $scenario;
        $this->sleeps = [];
        $this->fixture = FixtureTransport::load($this->fixtureDir() . '/' . $scenario . '.json');
        self::assertSame($scenario, $this->fixture['scenario'] ?? null, 'Fixture scenario name');

        if ($this->live()) {
            if (($this->fixture['live'] ?? true) === false) {
                self::markTestSkipped('Scenario ' . $scenario . ' cannot be triggered on demand.');
            }
            if (!$this->liveReady()) {
                self::markTestSkipped('AI_LIVE=1 needs the provider API key.');
            }
            $this->transport = new RecordingTransport(new CurlTransport(120.0));

            return $this->client($this->liveProfile(), $this->transport);
        }

        $this->transport = new FixtureTransport($this->fixture);

        return $this->client($this->profile(), $this->transport);
    }

    /**
     * A client with the retry middleware (sleep stubbed) on one model.
     */
    protected function client(ModelProfile $profile, TransportInterface $transport): Client
    {
        return new Client(
            new ModelCatalog([$profile->name => $profile], $profile->name),
            new ProviderRegistry([$profile->provider => $this->provider($transport)]),
            [new RetryMiddleware(new RetryPolicy(), null, function (int $ms): void {
                $this->sleeps[] = $ms;
            })],
        );
    }

    /**
     * End a scenario: offline every exchange must have been used; when recording, write the fixture.
     */
    protected function finish(): void
    {
        $transport = $this->transport();
        if ($transport instanceof FixtureTransport) {
            self::assertTrue($transport->exhausted(), 'Every recorded exchange of ' . $this->scenario . ' is used.');
        }
        if ($transport instanceof RecordingTransport && $this->recording()) {
            $meta = [];
            foreach (['scenario', 'provider', 'live'] as $key) {
                if (isset($this->fixture[$key])) {
                    $meta[$key] = $this->fixture[$key];
                }
            }
            $meta['model'] = $this->liveProfile()->id;
            $transport->save($this->fixtureDir() . '/' . $this->scenario . '.json', $meta);
        }
    }

    /**
     * The requests sent so far in this scenario.
     *
     * @return list<\Ldkafka\Ai\Http\HttpRequest>
     */
    protected function requests(): array
    {
        return $this->transport()->requests;
    }

    protected function live(): bool
    {
        return getenv('AI_LIVE') === '1';
    }

    protected function recording(): bool
    {
        return $this->live() && getenv('AI_RECORD') === '1';
    }

    private function transport(): FixtureTransport|RecordingTransport
    {
        self::assertNotNull($this->transport, 'begin() was not called');

        return $this->transport;
    }

    private static function weatherSpec(): ToolSpec
    {
        return ToolSpec::fromTool(self::weatherTool(static fn (array $arguments): string => ''));
    }

    /**
     * @param \Closure(array<string, mixed>): string $handler
     */
    private static function weatherTool(\Closure $handler): ToolInterface
    {
        return new class ($handler) implements ToolInterface {
            /**
             * @param \Closure(array<string, mixed>): string $handler
             */
            public function __construct(private \Closure $handler)
            {
            }

            public function getName(): string
            {
                return 'get_weather';
            }

            public function getDescription(): string
            {
                return 'Current weather for a city.';
            }

            /**
             * @return array<string, mixed>
             */
            public function getInputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string', 'description' => 'City name']],
                    'required' => ['city'],
                ];
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function handle(array $arguments): string
            {
                return ($this->handler)($arguments);
            }
        };
    }
}
