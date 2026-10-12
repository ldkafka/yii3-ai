<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Pipeline;

use Ldkafka\Ai\Exception\AiException;
use Ldkafka\Ai\Exception\ErrorKind;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Exception\TransportException;

use function ceil;
use function min;
use function mt_getrandmax;
use function mt_rand;

/**
 * When to retry and how long to wait.
 *
 * Retried: rate limits that carry retry-after, overloaded, server errors, provider timeouts
 * (408, 504) and connection failures before the request was sent. Not retried: a 429 without
 * retry-after (the spend cap), a read timeout or a connection dropped after the request was sent
 * (the call may have run and been billed), and every other error. A retry-after
 * longer than $maxRetryAfterSeconds is not waited out: the exception surfaces with retryAfter set.
 */
final readonly class RetryPolicy
{
    /**
     * @param int $maxRetries Retries after the first attempt.
     * @param int $baseDelayMs Backoff base.
     * @param int $maxDelayMs Backoff cap.
     * @param int $maxRetryAfterSeconds Longest retry-after the policy waits.
     */
    public function __construct(
        public int $maxRetries = 2,
        public int $baseDelayMs = 500,
        public int $maxDelayMs = 8000,
        public int $maxRetryAfterSeconds = 60,
    ) {
    }

    /**
     * Milliseconds to wait before retry number $retry (1-based), or null for no retry.
     */
    public function delayMs(int $retry, AiException $error): ?int
    {
        if ($retry < 1 || $retry > $this->maxRetries) {
            return null;
        }

        if ($error instanceof TransportException) {
            return $error->isAfterSend() ? null : $this->backoff($retry);
        }
        if (!$error instanceof ProviderException) {
            return null;
        }

        $retryable = match ($error->kind) {
            ErrorKind::RateLimited => $error->retryAfter !== null,
            ErrorKind::Overloaded, ErrorKind::Server, ErrorKind::Timeout => true,
            default => false,
        };
        if (!$retryable) {
            return null;
        }
        if ($error->retryAfter !== null) {
            if ($error->retryAfter > $this->maxRetryAfterSeconds) {
                return null;
            }

            return (int) ceil($error->retryAfter * 1000);
        }

        return $this->backoff($retry);
    }

    /**
     * min(maxDelayMs, baseDelayMs × 2^(retry−1)) with jitter × [0.75, 1].
     */
    private function backoff(int $retry): int
    {
        $delay = min($this->maxDelayMs, $this->baseDelayMs * 2 ** ($retry - 1));
        $jitter = 0.75 + 0.25 * mt_rand() / mt_getrandmax();

        return (int) ($delay * $jitter);
    }
}
