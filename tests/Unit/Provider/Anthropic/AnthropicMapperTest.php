<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Provider\Anthropic;

use Ldkafka\Ai\CacheHint;
use Ldkafka\Ai\CacheScope;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Exception\ErrorKind;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Http\HttpResponse;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\ProviderPart;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Message\Thinking;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Model\Capability;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Provider\Anthropic\AnthropicMapper;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\StopReason;
use Ldkafka\Ai\Tool\ToolChoice;
use Ldkafka\Ai\Tool\ToolSpec;
use Ldkafka\Ai\Tool\ToolChoiceMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class AnthropicMapperTest extends TestCase
{
    private AnthropicMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AnthropicMapper();
    }

    public function testMinimalPayloadUsesTheModelDefaults(): void
    {
        $payload = $this->mapper->toPayload(new Request([Message::user('', 'Hi')]), $this->model());

        self::assertSame(
            '{"model":"claude-x","max_tokens":64,"messages":[{"role":"user","content":[{"type":"text","text":"Hi"}]}]}',
            Json::encode($payload),
            'Empty text parts are skipped.',
        );
    }

    public function testMessagesLeftEmptyAreDropped(): void
    {
        $payload = $this->mapper->toPayload(new Request([
            Message::user('Hi'),
            Message::assistant(new Thinking('other', 'openai'), new ProviderPart('openai', '{"type":"x"}')),
        ]), $this->model());

        self::assertCount(1, Json::array($payload, 'messages'));
    }

    public function testOptionsMergeRecursivelyAndListsAreReplaced(): void
    {
        $model = $this->model(options: ['output_config' => ['effort' => 'high'], 'stop_sequences' => ['a', 'b']]);
        $request = new Request(
            [Message::user('Hi')],
            stop: ['z'],
            metadata: ['user_id' => 'u-1', 'tenant' => 'not sent'],
            providerOptions: [
                'anthropic' => ['output_config' => ['display' => 'summarized'], 'stop_sequences' => ['c'], 'betas' => ['b-1']],
                'openai' => ['ignored' => true],
            ],
        );

        $payload = $this->mapper->toPayload($request, $model);

        self::assertSame(['effort' => 'high', 'display' => 'summarized'], $payload['output_config']);
        self::assertSame(['c'], $payload['stop_sequences']);
        self::assertSame(['user_id' => 'u-1'], $payload['metadata']);
        self::assertArrayNotHasKey('betas', $payload);
        self::assertArrayNotHasKey('ignored', $payload);
        self::assertSame(['b-1'], $this->mapper->betas($request));
    }

    /**
     * @return iterable<string, array{ToolChoice, string}>
     */
    public static function choices(): iterable
    {
        yield 'auto' => [ToolChoice::auto(), '{"type":"auto"}'];
        yield 'auto serial' => [ToolChoice::auto(false), '{"type":"auto","disable_parallel_tool_use":true}'];
        yield 'none' => [ToolChoice::none(), '{"type":"none"}'];
        yield 'required' => [ToolChoice::required(), '{"type":"any"}'];
        yield 'required serial' => [ToolChoice::required(false), '{"type":"any","disable_parallel_tool_use":true}'];
        yield 'tool' => [ToolChoice::tool('t'), '{"type":"tool","name":"t"}'];
    }

    #[DataProvider('choices')]
    public function testToolChoice(ToolChoice $choice, string $expected): void
    {
        $payload = $this->mapper->toPayload(new Request([Message::user('x')], toolChoice: $choice), $this->model());

        self::assertSame($expected, Json::encode($payload['tool_choice']));
    }

    public function testToolSchemaPropertiesAreAlwaysAnObject(): void
    {
        $payload = $this->mapper->toPayload(new Request([Message::user('x')], tools: [
            new ToolSpec('a', 'A', []),
            new ToolSpec('b', 'B', ['type' => 'object', 'properties' => []]),
            new ToolSpec('c', 'C', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]),
        ]), $this->model());

        self::assertSame(
            '[{"name":"a","description":"A","input_schema":{"type":"object","properties":{}}},'
            . '{"name":"b","description":"B","input_schema":{"type":"object","properties":{}}},'
            . '{"name":"c","description":"C","input_schema":{"type":"object","properties":{"q":{"type":"string"}}}}]',
            Json::encode($payload['tools']),
        );
    }

    public function testToolResultWithSeveralPartsAndTheErrorFlag(): void
    {
        $payload = $this->mapper->toPayload(new Request([Message::user(
            new ToolResult('c1', 't', [new Text('a'), new Text(''), new Text('b')]),
            new ToolResult('c2', 't', [], true),
        )]), $this->model());
        $first = Json::array($payload, 'messages')[0] ?? null;
        self::assertIsArray($first);

        self::assertSame(
            '[{"type":"tool_result","tool_use_id":"c1","content":[{"type":"text","text":"a"},{"type":"text","text":"b"}]},'
            . '{"type":"tool_result","tool_use_id":"c2","is_error":true}]',
            Json::encode(Json::array($first, 'content')),
        );
    }

    public function testCacheHintsAreIgnoredWithoutPromptCache(): void
    {
        $payload = $this->mapper->toPayload(
            new Request([Message::user('x')], system: 'S', cache: [new CacheHint(CacheScope::System), new CacheHint(CacheScope::Messages)]),
            $this->model(capabilities: []),
        );

        self::assertSame('S', $payload['system']);
        self::assertArrayNotHasKey('cache_control', $payload);
    }

    public function testUnsupportedCacheTtl(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->mapper->toPayload(new Request([Message::user('x')], cache: [new CacheHint(CacheScope::Messages, 600)]), $this->model());
    }

    public function testDuplicateCacheScope(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->mapper->toPayload(new Request([Message::user('x')], cache: [
            new CacheHint(CacheScope::Messages),
            new CacheHint(CacheScope::Messages, 3600),
        ]), $this->model());
    }

    /**
     * @return iterable<string, array{string, StopReason}>
     */
    public static function stopReasons(): iterable
    {
        yield 'end_turn' => ['end_turn', StopReason::Complete];
        yield 'pause_turn' => ['pause_turn', StopReason::Paused];
        yield 'context' => ['model_context_window_exceeded', StopReason::ContextLimit];
        yield 'unknown' => ['something_new', StopReason::Other];
    }

    #[DataProvider('stopReasons')]
    public function testStopReasons(string $raw, StopReason $expected): void
    {
        $response = $this->mapper->toResponse('{"content":[],"stop_reason":"' . $raw . '","usage":{}}', $this->model());

        self::assertSame($expected, $response->stopReason);
        self::assertSame($raw, $response->rawStopReason);
        self::assertSame('claude-x', $response->model, 'The profile id when the body has no model.');
    }

    public function testUsageWithReasoningTokens(): void
    {
        $response = $this->mapper->toResponse(
            '{"content":[{"type":"text","text":"x"}],"stop_reason":"end_turn","usage":{"input_tokens":5,"output_tokens":9,"output_tokens_details":{"thinking_tokens":4},"cache_read_input_tokens":null}}',
            $this->model(),
            'req_1',
        );

        self::assertSame(4, $response->usage->reasoningTokens);
        self::assertSame(0, $response->usage->cacheReadTokens);
        self::assertSame('req_1', $response->requestId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'not json' => ['<html>'];
        yield 'no content' => ['{"type":"message"}'];
        yield 'scalar' => ['42'];
        yield 'block not an object' => ['{"content":["x"]}'];
        yield 'tool input not an object' => ['{"content":[{"type":"tool_use","id":"a","name":"b","input":[1]}]}'];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidResponse(string $body): void
    {
        try {
            $this->mapper->toResponse($body, $this->model(), 'req_9');
            self::fail('Expected an invalid response error');
        } catch (ProviderException $e) {
            self::assertSame(ErrorKind::InvalidResponse, $e->kind);
            self::assertSame('req_9', $e->requestId);
        }
    }

    /**
     * @return iterable<string, array{int, array<string, string>, string, ErrorKind}>
     */
    public static function errors(): iterable
    {
        $json = static fn (string $type): string => '{"type":"error","error":{"type":"' . $type . '","message":"m"},"request_id":"req_body"}';
        yield '402' => [402, [], $json('billing_error'), ErrorKind::Billing];
        yield '403' => [403, [], $json('permission_error'), ErrorKind::Permission];
        yield '404' => [404, [], $json('not_found_error'), ErrorKind::NotFound];
        yield '408' => [408, [], 'Request Timeout', ErrorKind::Timeout];
        yield '409' => [409, [], $json('conflict_error'), ErrorKind::InvalidRequest];
        yield '422' => [422, [], $json('invalid_request_error'), ErrorKind::InvalidRequest];
        yield '429 retry-after' => [429, ['retry-after' => '7'], $json('rate_limit_error'), ErrorKind::RateLimited];
        yield '429 plain' => [429, [], $json('rate_limit_error'), ErrorKind::RateLimited];
        yield '502' => [502, [], 'Bad Gateway', ErrorKind::Server];
        yield '504' => [504, [], $json('timeout_error'), ErrorKind::Timeout];
        yield '529' => [529, [], $json('overloaded_error'), ErrorKind::Overloaded];
        yield '500 overloaded type' => [500, [], $json('overloaded_error'), ErrorKind::Overloaded];
        yield '302' => [302, [], '', ErrorKind::Unknown];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('errors')]
    public function testErrorKinds(int $status, array $headers, string $body, ErrorKind $kind): void
    {
        $e = $this->mapper->toException(new HttpResponse($status, $headers, $body));

        self::assertSame($kind, $e->kind);
        self::assertSame($status, $e->httpStatus);
        self::assertSame('anthropic', $e->provider);
    }

    public function testErrorMessageAndRetryAfter(): void
    {
        $e = $this->mapper->toException(new HttpResponse(
            429,
            ['Retry-After' => '7', 'request-id' => 'req_h'],
            '{"type":"error","error":{"type":"rate_limit_error","message":"Slow down"},"request_id":"req_body"}',
        ));

        self::assertSame('Anthropic API error (HTTP 429 rate_limit_error): Slow down [request req_h]', $e->getMessage());
        self::assertSame(7.0, $e->retryAfter);
        self::assertSame('req_h', $e->requestId);
    }

    public function testNonJsonBodyKeepsAShortSnippet(): void
    {
        $e = $this->mapper->toException(new HttpResponse(502, [], '<html><body><h1>Bad   gateway</h1>' . str_repeat('x', 1000) . '</body></html>'));

        self::assertStringStartsWith('Anthropic API error (HTTP 502): Bad gateway', $e->getMessage());
        self::assertLessThan(400, strlen($e->getMessage()));
        self::assertNull($e->errorType);
        self::assertNull($e->retryAfter);
        self::assertSame('Anthropic API error (HTTP 500): empty body', $this->mapper->toException(new HttpResponse(500, [], ''))->getMessage());
    }

    /**
     * @param list<Capability>|null $capabilities
     * @param array<string, mixed> $options
     */
    private function model(?array $capabilities = null, array $options = []): ModelProfile
    {
        return new ModelProfile('x', 'anthropic', 'claude-x', $capabilities ?? Capability::cases(), 1000, 128, 64, null, $options);
    }

    public function testOneHourHintAfterAFiveMinuteHintIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->mapper->toPayload(new Request([Message::user('x')], system: 'S', tools: [new ToolSpec('t', 'd', [])], cache: [
            new CacheHint(CacheScope::System, 3600),
            new CacheHint(CacheScope::Tools),
        ]), $this->model());
    }

    public function testOneHourHintsBeforeFiveMinuteHintsAreAccepted(): void
    {
        $payload = $this->mapper->toPayload(new Request([Message::user('x')], system: 'S', tools: [new ToolSpec('t', 'd', [])], cache: [
            new CacheHint(CacheScope::Messages),
            new CacheHint(CacheScope::System, 300),
            new CacheHint(CacheScope::Tools, 3600),
        ]), $this->model());

        self::assertSame(['type' => 'ephemeral'], $payload['cache_control']);
    }

    public function testToolChoiceModeIsAnEnum(): void
    {
        self::assertSame(ToolChoiceMode::Tool, ToolChoice::tool('t')->mode);
        self::assertSame(ToolChoiceMode::Required, ToolChoice::required()->mode);
        self::assertSame(ToolChoiceMode::Auto, ToolChoice::auto()->mode);
        self::assertSame(ToolChoiceMode::None, ToolChoice::none()->mode);
    }
}
