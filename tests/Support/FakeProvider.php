<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Support;

use Ldkafka\Ai\Exception\AiException;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Provider\ProviderInterface;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;
use Ldkafka\Ai\StopReason;
use Ldkafka\Ai\Usage;
use RuntimeException;

use function array_shift;

/**
 * Answers from a queue of responses or exceptions and records every request.
 */
final class FakeProvider implements ProviderInterface
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response|AiException> */
    private array $queue;

    /**
     * @param list<Response|AiException> $queue
     */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    /**
     * A response with the given message and stop reason.
     */
    public static function response(Message $message, StopReason $stop = StopReason::Complete, ?Usage $usage = null): Response
    {
        return new Response($message, $stop, $stop->value, $usage ?? new Usage(10, 5), 'fake', 'fake-model');
    }

    /**
     * Queue another answer.
     */
    public function push(Response|AiException $answer): void
    {
        $this->queue[] = $answer;
    }

    /**
     * Pop the next answer.
     */
    public function send(Request $request, ModelProfile $model): Response
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new RuntimeException('FakeProvider has no answer queued.');
        }
        if ($next instanceof AiException) {
            throw $next;
        }

        return $next;
    }
}
