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
use Ldkafka\Ai\Model\CostCalculator;
use Ldkafka\Ai\Model\ModelCatalog;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Pipeline\RetryMiddleware;
use Ldkafka\Ai\Pipeline\RetryPolicy;
use Ldkafka\Ai\Provider\ProviderInterface;
use Ldkafka\Ai\Provider\ProviderRegistry;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\ResponseFormat;
use Ldkafka\Ai\StopReason;
use Ldkafka\Ai\Usage;
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
use function array_is_list;
use function array_values;
use function count;
use function getenv;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function ksort;
use function sprintf;

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
    protected const IMAGE_URL = 'https://raw.githubusercontent.com/ldkafka/yii3-ai/main/tests/Conformance/media/test-image.png'; // our own asset: some hosts refuse Anthropic's fetcher

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
        // The mapped values equal the body that was received (live) or replayed (offline).
        $body = $this->responseBody(0);
        $texts = [];
        foreach (self::blocksOf(Json::array($body, 'content'), 'text') as $block) {
            $texts[] = Json::string($block, 'text') ?? '';
        }
        self::assertSame(implode('', $texts), $response->text());
        self::assertSame(Json::string($body, 'model'), $response->model);
        self::assertSame(Json::string($body, 'id'), $response->id);
        $usage = Json::array($body, 'usage');
        self::assertSame(Json::int($usage, 'input_tokens'), $response->usage->inputTokens);
        self::assertSame(Json::int($usage, 'output_tokens'), $response->usage->outputTokens);
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
        self::assertNotSame([], $calls);
        self::assertCount(count(self::blocksOf(Json::array($this->responseBody(0), 'content'), 'tool_use')), $calls);
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

        // Sent: the replayed call keeps {} and nested {} exactly (the wire body, read back with objects kept).
        $sent = self::blocksOf(self::contentOf($this->requestBody(0), 1), 'tool_use');
        self::assertSame('{"q":"saved","filters":{},"options":{"nested":{}},"tags":[]}', Json::encode($sent[0]['input'] ?? null));
        // Received: the call's JSON is the input the API sent, whatever the model chose to put in it.
        $received = self::blocksOf(Json::array($this->responseBody(0), 'content'), 'tool_use');
        self::assertSame(Json::encode($received[0]['input'] ?? null), $call->argumentsJson);
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
        // Adaptive thinking: the model decides whether to think, so the answer may hold no thinking
        // blocks (Haiku 5.5 did not for this prompt). Whatever blocks came back must map one to one
        // and be echoed verbatim in the next request. Models with thinking always on (Opus 5.5,
        // Fable 5.1) exercise the echo live; the mapper unit tests cover it with both block types.
        $thinking = array_values(array_filter($first->message->parts, static fn ($part): bool => $part instanceof Thinking));
        $blocks = self::blocksOf(Json::array($this->responseBody(0), 'content'), 'thinking', 'redacted_thinking');
        self::assertCount(count($blocks), $thinking);
        foreach ($blocks as $index => $block) {
            $part = $thinking[$index];
            self::assertInstanceOf(Thinking::class, $part);
            self::assertSame('anthropic', $part->provider);
            if (Json::string($block, 'type') === 'redacted_thinking') {
                self::assertSame(Json::string($block, 'data'), $part->opaque['data'] ?? null);
            } else {
                self::assertSame(Json::string($block, 'thinking') ?? '', $part->text);
                self::assertSame(Json::string($block, 'signature'), $part->opaque['signature'] ?? null);
            }
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
            $echoed = self::blocksOf(self::contentOf($this->requestBody(1), 1), 'thinking', 'redacted_thinking');
            self::assertSame(Json::encode(self::canonical($blocks)), Json::encode(self::canonical($echoed)));
        }
        $this->finish();
    }

    /**
     * Two calls on one long, fixed prefix: the first writes the prompt cache, the second reads it.
     *
     * The system prompt is about 5,000 tokens, well over the 512-token cache minimum of every
     * Claude 5 model (shorter prompts are silently not cached). Live, the scenario costs about
     * $0.001 on Haiku 5.5.
     */
    public function testPromptCache(): void
    {
        $client = $this->begin('prompt_cache');
        $request = new Request(
            [],
            system: self::cachePrefix(),
            tools: [self::weatherSpec()],
            maxTokens: 256,
            cache: [
                new CacheHint(CacheScope::Tools, 3600),
                new CacheHint(CacheScope::System),
                new CacheHint(CacheScope::Messages),
            ],
        );
        $first = $client->complete($request->withMessages([Message::user('Which rule covers day 7? Answer with the rule number only.')]));
        $second = $client->complete($request->withMessages([Message::user('Which rule covers day 12? Answer with the rule number only.')]));

        foreach ([$first, $second] as $index => $response) {
            self::assertSame(StopReason::Complete, $response->stopReason);

            // Sent: the three hints became cache_control at the documented places.
            $sent = $this->requestBody($index);
            self::assertSame(['type' => 'ephemeral'], $sent['cache_control'] ?? null);
            $systemBlock = Json::array($sent, 'system')[0] ?? null;
            self::assertIsArray($systemBlock);
            self::assertSame(['type' => 'ephemeral'], $systemBlock['cache_control'] ?? null);
            $tools = Json::array($sent, 'tools');
            $lastTool = $tools[count($tools) - 1] ?? null;
            self::assertIsArray($lastTool);
            self::assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $lastTool['cache_control'] ?? null);

            // Received: the usage counters, the 1h split and the cost follow the body.
            $usage = Json::array($this->responseBody($index), 'usage');
            $creation = Json::array($usage, 'cache_creation');
            $expected = new Usage(
                Json::int($usage, 'input_tokens'),
                Json::int($usage, 'output_tokens'),
                Json::int($usage, 'cache_read_input_tokens'),
                Json::int($usage, 'cache_creation_input_tokens'),
                Json::int(Json::array($usage, 'output_tokens_details'), 'thinking_tokens'),
                isset($creation['ephemeral_1h_input_tokens']) ? ['cacheWrite1h' => Json::int($creation, 'ephemeral_1h_input_tokens')] : [],
            );
            self::assertEquals($expected, $response->usage);
            $price = $this->currentProfile()->price;
            self::assertNotNull($price);
            self::assertNotNull($response->cost);
            self::assertEqualsWithDelta((new CostCalculator())->cost($expected, $price)->total(), $response->cost->total(), 1e-12);
        }

        if ($this->live() || ($this->fixture['synthetic'] ?? true) === false) {
            self::assertGreaterThan(0, $first->usage->cacheWriteTokens, 'The first call writes the cache.');
            self::assertGreaterThan(0, $second->usage->cacheReadTokens, 'The second call reads the same prefix.');
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

    /**
     * A fixed system prompt of about 5,000 tokens (70 numbered rules, about 13,000 characters),
     * the same on every run.
     */
    protected static function cachePrefix(): string
    {
        $lines = ['You are a support agent for a holiday rental site. Answer from these house rules only.'];
        for ($rule = 1; $rule <= 70; $rule++) {
            $lines[] = sprintf(
                'Rule %d: a booking whose arrival falls on day %d of the season may change its dates up to %d days before arrival, '
                . 'pays a cleaning fee of %d dollars, and allows at most %d guests in the unit.',
                $rule,
                $rule,
                ($rule % 14) + 1,
                40 + ($rule % 9) * 5,
                2 + ($rule % 6),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The profile the scenario runs on.
     */
    protected function currentProfile(): ModelProfile
    {
        return $this->live() ? $this->liveProfile() : $this->profile();
    }

    /**
     * The decoded body of a response received (live) or replayed (offline), empty objects kept.
     *
     * @return array<mixed>
     */
    protected function responseBody(int $index): array
    {
        $responses = $this->transport()->responses;
        self::assertArrayHasKey($index, $responses);
        $decoded = Json::decodeKeepEmptyObjects($responses[$index]->body);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The decoded body of a request sent, empty objects kept.
     *
     * @return array<mixed>
     */
    protected function requestBody(int $index): array
    {
        $requests = $this->requests();
        self::assertArrayHasKey($index, $requests);
        $decoded = Json::decodeKeepEmptyObjects($requests[$index]->body);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The content blocks of message $index in a request body.
     *
     * @param array<mixed> $body
     * @return array<mixed>
     */
    protected static function contentOf(array $body, int $index): array
    {
        $message = Json::array($body, 'messages')[$index] ?? null;
        self::assertIsArray($message);

        return Json::array($message, 'content');
    }

    /**
     * The blocks of the given types, in order.
     *
     * @param array<mixed> $content
     * @return list<array<mixed>>
     */
    protected static function blocksOf(array $content, string ...$types): array
    {
        $out = [];
        foreach ($content as $block) {
            if (is_array($block) && in_array(Json::string($block, 'type'), $types, true)) {
                $out[] = $block;
            }
        }

        return $out;
    }

    /**
     * A value with object keys sorted, so key order does not matter in comparisons.
     */
    protected static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonical($item);
        }
        if (!array_is_list($out)) {
            ksort($out);
        }

        return $out;
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
