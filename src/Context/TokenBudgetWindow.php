<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Context;

use InvalidArgumentException;
use Ldkafka\Ai\Exception\ContextOverflowException;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\Role;
use Ldkafka\Ai\Message\Thinking;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Request;

use function array_slice;
use function count;
use function min;

/**
 * Sends the newest whole turns within a token budget.
 *
 * A turn starts at a user message without tool results. Within budget the request is unchanged.
 * Over budget, when the last message starts a turn, whole turns are dropped from the oldest until
 * the estimate is at most trimTo x budget (never the last turn), so the cut moves seldom and the
 * cached prefix survives most turns. Over budget during an open tool exchange the request is left
 * unchanged until the next turn. Once anything is dropped, every Thinking part is removed (a
 * replayed thinking block whose earlier history changed is rejected) and assistant messages left
 * empty go. The last turn alone over budget throws ContextOverflowException.
 */
final class TokenBudgetWindow implements ContextPolicyInterface
{
    /**
     * @param int $budgetTokens The token budget for system, tools and messages.
     * @param TokenEstimatorInterface $estimator Estimates tokens.
     * @param float $trimTo The fraction of the budget to trim down to, in (0, 1].
     * @throws InvalidArgumentException On a non-positive budget or a trimTo outside (0, 1].
     */
    public function __construct(
        private int $budgetTokens,
        private TokenEstimatorInterface $estimator = new HeuristicTokenEstimator(),
        private float $trimTo = 0.75,
    ) {
        if ($budgetTokens < 1) {
            throw new InvalidArgumentException('The token budget must be positive.');
        }
        if ($trimTo <= 0.0 || $trimTo > 1.0) {
            throw new InvalidArgumentException('trimTo must be in (0, 1].');
        }
    }

    /**
     * The request with older turns dropped when it is over budget.
     */
    public function apply(Request $request, ModelProfile $model): Request
    {
        $budget = min($this->budgetTokens, $model->maxContext - ($request->maxTokens ?? $model->maxTokens));
        $fixed = $this->estimator->text((string) $request->system);
        if ($request->tools !== []) {
            $fixed += $this->estimator->text(Json::encode($request->tools));
        }

        $messages = $request->messages;
        if ($messages === []) {
            return $request;
        }
        $sizes = [];
        $total = $fixed;
        foreach ($messages as $index => $message) {
            $sizes[$index] = $this->estimator->message($message);
            $total += $sizes[$index];
        }
        if ($total <= $budget) {
            return $request;
        }

        $last = count($messages) - 1;
        if (!self::startsTurn($messages[$last])) {
            return $request;
        }

        // $suffix[$i] = fixed + the estimate of messages $i..last.
        $suffix = [];
        $running = $fixed;
        for ($i = $last; $i >= 0; $i--) {
            $running += $sizes[$i];
            $suffix[$i] = $running;
        }

        $cut = null;
        for ($i = 1; $i < $last; $i++) {
            if (self::startsTurn($messages[$i]) && $suffix[$i] <= $this->trimTo * $budget) {
                $cut = $i;
                break;
            }
        }
        if ($cut === null) {
            if ($suffix[$last] > $budget) {
                throw new ContextOverflowException('The newest turn alone exceeds the context budget of ' . $budget . ' tokens.');
            }
            $cut = $last;
        }

        return $request->withMessages(self::withoutThinking(array_slice($messages, $cut)));
    }

    /**
     * Whether the message starts a turn: a user message without tool results.
     */
    private static function startsTurn(Message $message): bool
    {
        if ($message->role !== Role::User) {
            return false;
        }
        foreach ($message->parts as $part) {
            if ($part instanceof ToolResult) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Message> $messages
     * @return list<Message>
     */
    private static function withoutThinking(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $parts = [];
            foreach ($message->parts as $part) {
                if (!$part instanceof Thinking) {
                    $parts[] = $part;
                }
            }
            if ($parts === [] && $message->role === Role::Assistant) {
                continue;
            }
            $out[] = $parts === $message->parts ? $message : new Message($message->role, $parts);
        }

        return $out;
    }
}
