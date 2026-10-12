<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that records the request and returns or throws a fixed answer.
 */
final class RecordingPsr18Client implements ClientInterface
{
    public ?RequestInterface $sent = null;

    /**
     * @param ResponseInterface|NetworkExceptionInterface $answer Returned or thrown on every call.
     */
    public function __construct(private ResponseInterface|NetworkExceptionInterface $answer)
    {
    }

    /**
     * Record the request and answer.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent = $request;
        if ($this->answer instanceof NetworkExceptionInterface) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
