<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Support;

use Ldkafka\Ai\Exception\TransportException;
use Ldkafka\Ai\Http\HttpRequest;
use Ldkafka\Ai\Http\HttpResponse;
use Ldkafka\Ai\Http\TransportInterface;
use RuntimeException;

use function array_shift;

/**
 * Answers from a queue of responses or transport exceptions and records every request.
 */
final class QueueTransport implements TransportInterface
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<HttpResponse|TransportException> */
    private array $queue;

    /**
     * @param list<HttpResponse|TransportException> $queue
     */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    /**
     * Pop the next answer.
     */
    public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new RuntimeException('QueueTransport has no answer queued.');
        }
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }
}
