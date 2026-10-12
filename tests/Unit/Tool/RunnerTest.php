<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Tool;

use InvalidArgumentException;
use Ldkafka\Ai\Client;
use Ldkafka\Ai\Context\ContextPolicyInterface;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Exception\ToolException;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\Role;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Model\Capability;
use Ldkafka\Ai\Model\ModelCatalog;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Model\Price;
use Ldkafka\Ai\Provider\ProviderRegistry;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;
use Ldkafka\Ai\StopReason;
use Ldkafka\Ai\Tests\Support\ArrayLogger;
use Ldkafka\Ai\Tests\Support\CallbackTool;
use Ldkafka\Ai\Tests\Support\FakeProvider;
use Ldkafka\Ai\Tool\Runner;
use Ldkafka\Ai\Tool\RunStatus;
use Ldkafka\Ai\Tool\ToolRegistry;
use Ldkafka\Ai\Tool\ToolSpec;
use Ldkafka\Ai\Usage;
use LogicException;
use Ldkafka\Ai\Tool\ToolChoice;
use Ldkafka\Ai\Tool\ToolChoiceMode;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase
{
    private FakeProvider $provider;

    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->provider = new FakeProvider();
        $this->logger = new ArrayLogger();
    }

    public function testToolLoopAnswersEveryCallInOneMessage(): void
    {
        $weather = new CallbackTool('weather', static fn (array $a): string => 'sunny in ' . (is_string($city = $a['city'] ?? null) ? $city : ''));
        $chart = new CallbackTool('chart', static fn (array $a): array => [new Text('chart'), new Image('image/png', 'AAAA')]);
        $this->provider->push($this->toolUse(ToolCall::of('c1', 'weather', ['city' => 'Paris']), ToolCall::of('c2', 'chart', [])));
        $this->provider->push(FakeProvider::response(Message::assistant('Done.')));
        $seen = [];

        $result = $this->runner(new ToolRegistry([$weather, $chart]))->run(
            new Request([Message::user('Go')]),
            static function (Message $message, ?Response $response) use (&$seen): void {
                $seen[] = [$message->role, $response !== null];
            },
        );

        self::assertSame(RunStatus::Completed, $result->status);
        self::assertSame(2, $result->steps);
        self::assertSame('Done.', $result->text());
        self::assertCount(3, $result->messages);
        self::assertSame([[Role::Assistant, true], [Role::User, false], [Role::Assistant, true]], $seen);
        $results = $result->messages[1]->parts;
        self::assertCount(2, $results);
        self::assertInstanceOf(ToolResult::class, $results[0]);
        self::assertInstanceOf(ToolResult::class, $results[1]);
        self::assertSame(['c1', 'c2'], [$results[0]->callId, $results[1]->callId]);
        self::assertEquals([new Text('sunny in Paris')], $results[0]->content);
        self::assertCount(2, $results[1]->content);
        self::assertEquals(new Usage(20, 10), $result->usage);
        self::assertNotNull($result->cost);

        // Every step offers exactly the registry's tools.
        foreach ($this->provider->requests as $request) {
            self::assertSame(['weather', 'chart'], array_map(static fn (ToolSpec $s): string => $s->name, $request->tools));
        }
        self::assertCount(3, $this->provider->requests[1]->messages);
    }

    public function testUnknownToolAndFailuresBecomeErrorResults(): void
    {
        $failing = new CallbackTool('failing', static function (array $a): string {
            throw new LogicException('database password is hunter2');
        });
        $refusing = new CallbackTool('refusing', static function (array $a): string {
            throw new ToolException('City not found.');
        });
        $hidden = new CallbackTool('hidden', static fn (array $a): string => 'never');
        $this->provider->push($this->toolUse(new ToolCall('c1', 'nope'), new ToolCall('c2', 'failing'), new ToolCall('c3', 'refusing'), new ToolCall('c4', 'hidden')));
        $this->provider->push(FakeProvider::response(Message::assistant('Sorry.')));

        $result = $this->runner((new ToolRegistry([$failing, $refusing, $hidden]))->only('failing', 'refusing'))->run(new Request([Message::user('Go')]));

        $texts = [];
        foreach ($result->messages[1]->parts as $part) {
            self::assertInstanceOf(ToolResult::class, $part);
            self::assertTrue($part->isError);
            $texts[] = $part->content[0] instanceof Text ? $part->content[0]->text : '';
        }
        self::assertSame([
            'Tool nope is not available.',
            'Tool failing failed.',
            'City not found.',
            'Tool hidden is not available.',
        ], $texts);
        self::assertSame([], $hidden->calls);
        self::assertTrue($this->logger->has('warning', 'Tool failing failed: LogicException: database password is hunter2'));
        self::assertStringNotContainsString('hunter2', (string) json_encode($this->provider->requests[1]->messages[2]->toArray()));
    }

    public function testStepLimit(): void
    {
        $tool = new CallbackTool('t', static fn (array $a): string => 'x');
        for ($i = 0; $i < 3; $i++) {
            $this->provider->push($this->toolUse(new ToolCall('c' . $i, 't')));
        }

        $result = $this->runner(new ToolRegistry([$tool]), maxSteps: 3)->run(new Request([Message::user('Go')]));

        self::assertSame(RunStatus::StepLimit, $result->status);
        self::assertSame(3, $result->steps);
        self::assertCount(6, $result->messages, 'The last tool call is still answered, so the history stays valid.');
    }

    public function testBudgetExhausted(): void
    {
        $tool = new CallbackTool('t', static fn (array $a): string => 'x');
        // Each step costs 1M input tokens at $1 = $1.00.
        $this->provider->push(FakeProvider::response(Message::assistant(new ToolCall('c1', 't')), StopReason::ToolUse, new Usage(1_000_000)));
        $this->provider->push(FakeProvider::response(Message::assistant(new ToolCall('c2', 't')), StopReason::ToolUse, new Usage(1_000_000)));

        $result = $this->runner(new ToolRegistry([$tool]), maxCostUsd: 1.5)->run(new Request([Message::user('Go')]));

        self::assertSame(RunStatus::BudgetExhausted, $result->status);
        self::assertSame(2, $result->steps);
        self::assertNotNull($result->cost);
        self::assertEqualsWithDelta(2.0, $result->cost->total(), 1e-9, 'The budget is soft: the second call overshoots it.');
    }

    public function testPausedIsResentAndCountsAsAStep(): void
    {
        $this->provider->push(FakeProvider::response(Message::assistant('Part one.'), StopReason::Paused));
        $this->provider->push(FakeProvider::response(Message::assistant('Part two.')));

        $result = $this->runner()->run(new Request([Message::user('Go')]));

        self::assertSame(2, $result->steps);
        self::assertSame('Part two.', $result->text());
        $resent = $this->provider->requests[1]->messages;
        self::assertCount(2, $resent);
        self::assertSame(Role::Assistant, $resent[1]->role);
    }

    public function testToolCallsInAMaxTokensResponseAreNotRun(): void
    {
        $tool = new CallbackTool('t', static fn (array $a): string => 'x');
        $this->provider->push(FakeProvider::response(Message::assistant(new ToolCall('c1', 't')), StopReason::MaxTokens));

        $result = $this->runner(new ToolRegistry([$tool]))->run(new Request([Message::user('Go')]));

        self::assertSame(RunStatus::Completed, $result->status);
        self::assertSame(StopReason::MaxTokens, $result->response->stopReason);
        self::assertSame([], $tool->calls);
    }

    public function testContextPolicyIsAppliedOnce(): void
    {
        $policy = new class implements ContextPolicyInterface {
            public int $calls = 0;

            public function apply(Request $request, ModelProfile $model): Request
            {
                $this->calls++;

                return $request->withMessages([Message::user('trimmed')]);
            }
        };
        $tool = new CallbackTool('t', static fn (array $a): string => 'x');
        $this->provider->push($this->toolUse(new ToolCall('c1', 't')));
        $this->provider->push(FakeProvider::response(Message::assistant('ok')));

        $runner = new Runner($this->client(), new ToolRegistry([$tool]), $policy);
        $runner->run(new Request([Message::user('a'), Message::assistant('b'), Message::user('c')]));

        self::assertSame(1, $policy->calls);
        self::assertSame('trimmed', $this->provider->requests[0]->messages[0]->text());
        self::assertCount(3, $this->provider->requests[1]->messages);
    }

    public function testRequestToolsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner()->run(new Request([Message::user('x')], tools: [new ToolSpec('t', 'd', [])]));
    }

    public function testBudgetNeedsAPrice(): void
    {
        $this->expectException(ConfigurationException::class);
        (new Runner($this->client(), maxCostUsd: 1.0))->run(new Request([Message::user('x')], model: 'unpriced'));
    }

    public function testInvalidLimits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Runner($this->client(), maxSteps: 0);
    }

    public function testWithToolsReturnsACopy(): void
    {
        $tool = new CallbackTool('t', static fn (array $a): string => 'x');
        $runner = $this->runner();
        $this->provider->push($this->toolUse(new ToolCall('c1', 't')));
        $this->provider->push(FakeProvider::response(Message::assistant('ok')));

        $runner->withTools(new ToolRegistry([$tool]))->run(new Request([Message::user('x')]));

        self::assertCount(1, $tool->calls);
    }

    private function toolUse(ToolCall ...$calls): Response
    {
        return FakeProvider::response(Message::assistant(...$calls), StopReason::ToolUse);
    }

    private function runner(?ToolRegistry $tools = null, int $maxSteps = 10, ?float $maxCostUsd = null): Runner
    {
        return new Runner($this->client(), $tools ?? new ToolRegistry(), maxSteps: $maxSteps, maxCostUsd: $maxCostUsd, logger: $this->logger);
    }

    private function client(): Client
    {
        return new Client(new ModelCatalog([
            'priced' => new ModelProfile('priced', 'fake', 'priced', Capability::cases(), 1_000_000, 1000, 1000, new Price(1.0, 1.0)),
            'unpriced' => new ModelProfile('unpriced', 'fake', 'unpriced', Capability::cases(), 1_000_000, 1000, 1000),
        ], 'priced'), new ProviderRegistry(['fake' => $this->provider]));
    }

    public function testForcedToolChoiceAppliesToTheFirstStepOnly(): void
    {
        $tool = new CallbackTool('t', static fn (array $a): string => 'x');
        $this->provider->push($this->toolUse(new ToolCall('c1', 't')));
        $this->provider->push(FakeProvider::response(Message::assistant('Done.')));

        $result = $this->runner(new ToolRegistry([$tool]), maxSteps: 5)->run(new Request([Message::user('Go')], toolChoice: ToolChoice::required(false)));

        self::assertSame(RunStatus::Completed, $result->status);
        self::assertSame(ToolChoiceMode::Required, $this->provider->requests[0]->toolChoice?->mode);
        $second = $this->provider->requests[1]->toolChoice;
        self::assertNotNull($second);
        self::assertSame(ToolChoiceMode::Auto, $second->mode);
        self::assertFalse($second->parallel);
    }

    public function testNoneChoiceIsKept(): void
    {
        $this->provider->push(FakeProvider::response(Message::assistant('a'), StopReason::Paused));
        $this->provider->push(FakeProvider::response(Message::assistant('b')));

        $this->runner()->run(new Request([Message::user('Go')], toolChoice: ToolChoice::none()));

        self::assertSame(ToolChoiceMode::None, $this->provider->requests[1]->toolChoice?->mode);
    }
}
