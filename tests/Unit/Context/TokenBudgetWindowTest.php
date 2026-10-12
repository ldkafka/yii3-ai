<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Context;

use InvalidArgumentException;
use Ldkafka\Ai\Context\FullReplay;
use Ldkafka\Ai\Context\TokenBudgetWindow;
use Ldkafka\Ai\Context\TokenEstimatorInterface;
use Ldkafka\Ai\Exception\ContextOverflowException;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\Thinking;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Request;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

final class TokenBudgetWindowTest extends TestCase
{
    public function testWithinBudgetTheRequestIsUnchanged(): void
    {
        $request = new Request([self::user(10), self::assistant(10), self::user(10)]);

        self::assertSame($request, $this->window(50)->apply($request, $this->model()));
    }

    public function testDropsWholeTurnsDownToTrimToAndStripsThinking(): void
    {
        $request = new Request([
            self::user(10),
            Message::assistant(new Thinking('', 'anthropic', ['signature' => 's']), self::text(10)),
            self::user(10),
            self::assistant(10),
            self::user(10),
            Message::assistant(new Thinking('', 'anthropic', ['signature' => 's'])),
            Message::assistant(new Thinking('', 'anthropic', ['signature' => 's']), self::text(10)),
            self::user(10),
        ]);

        // 70 tokens, budget 50, trimTo 0.75: keep from the turn whose suffix fits in 37.5.
        $result = $this->window(50)->apply($request, $this->model());

        self::assertCount(3, $result->messages, 'The thinking-only assistant message goes.');
        self::assertSame(['user', 'assistant', 'user'], array_map(static fn (Message $m): string => $m->role->value, $result->messages));
        foreach ($result->messages as $message) {
            foreach ($message->parts as $part) {
                self::assertNotInstanceOf(Thinking::class, $part);
            }
        }
    }

    public function testFallsBackToTheLastTurnAlone(): void
    {
        $result = $this->window(50)->apply(new Request([self::user(10), self::assistant(10), self::user(45)]), $this->model());

        self::assertCount(1, $result->messages);
    }

    public function testOpenToolExchangeIsLeftAlone(): void
    {
        $request = new Request([
            self::user(40),
            Message::assistant(new ToolCall('c', 't')),
            Message::user(ToolResult::text('c', 't', str_repeat('x', 40))),
        ]);

        self::assertSame($request, $this->window(50)->apply($request, $this->model()));
    }

    public function testNewestTurnAloneOverBudget(): void
    {
        $this->expectException(ContextOverflowException::class);
        $this->window(50)->apply(new Request([self::user(10), self::assistant(10), self::user(60)]), $this->model());
    }

    public function testSystemAndToolsCountAndTheModelWindowCaps(): void
    {
        // Budget = min(10_000, maxContext 100 - maxTokens 40) = 60; the system takes 30.
        $request = new Request([self::user(15), self::assistant(15), self::user(15)], system: str_repeat('s', 30), maxTokens: 40);

        $result = $this->window(10_000)->apply($request, $this->model(100));

        self::assertCount(1, $result->messages);
    }

    public function testInvalidSettings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TokenBudgetWindow(100, $this->estimator(), 1.5);
    }

    public function testFullReplayIsUnchanged(): void
    {
        $request = new Request([self::user(10)]);

        self::assertSame($request, (new FullReplay())->apply($request, $this->model()));
    }

    private function window(int $budget): TokenBudgetWindow
    {
        return new TokenBudgetWindow($budget, $this->estimator());
    }

    /**
     * One token per character of text; nothing else counts.
     */
    private function estimator(): TokenEstimatorInterface
    {
        return new class implements TokenEstimatorInterface {
            public function message(Message $message): int
            {
                $tokens = 0;
                foreach ($message->parts as $part) {
                    if ($part instanceof \Ldkafka\Ai\Message\Text) {
                        $tokens += strlen($part->text);
                    } elseif ($part instanceof ToolResult) {
                        foreach ($part->content as $item) {
                            $tokens += $item instanceof \Ldkafka\Ai\Message\Text ? strlen($item->text) : 0;
                        }
                    }
                }

                return $tokens;
            }

            public function text(string $text): int
            {
                return strlen($text);
            }
        };
    }

    private function model(int $maxContext = 100_000): ModelProfile
    {
        return new ModelProfile('m', 'p', 'm', [], $maxContext, 100, 100);
    }

    private static function text(int $tokens): \Ldkafka\Ai\Message\Text
    {
        return new \Ldkafka\Ai\Message\Text(str_repeat('a', $tokens));
    }

    private static function user(int $tokens): Message
    {
        return Message::user(self::text($tokens));
    }

    private static function assistant(int $tokens): Message
    {
        return Message::assistant(self::text($tokens));
    }
}
