<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Exception;

use Throwable;

/**
 * The HTTP exchange failed: DNS, TLS, connect, a timeout, a dropped connection or a cancel by the
 * caller. The message names only the URL.
 */
final class TransportException extends AiException
{
    /**
     * @param string $message What failed.
     * @param bool $timeout Whether the request was sent and the answer timed out.
     * @param bool $afterSend Whether the request was sent before the exchange failed.
     * @param Throwable|null $previous The cause.
     * @param bool $cancelled Whether the caller cancelled the request (the transport's abort callback).
     */
    public function __construct(
        string $message,
        private readonly bool $timeout = false,
        private readonly bool $afterSend = false,
        ?Throwable $previous = null,
        private readonly bool $cancelled = false,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether the request was sent and the answer timed out. Such a call may have completed and
     * been billed, so it is not retried.
     */
    public function isTimeout(): bool
    {
        return $this->timeout;
    }

    /**
     * Whether the request had been sent when the exchange failed (a read timeout, a connection
     * reset or an empty reply). The call may have run and been billed, so it is not retried.
     */
    public function isAfterSend(): bool
    {
        return $this->afterSend || $this->timeout;
    }

    /**
     * Whether the caller cancelled the request. A cancelled call is never retried.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
