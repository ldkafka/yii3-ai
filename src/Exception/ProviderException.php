<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Exception;

use Throwable;

/**
 * The provider answered with an error. Callers `match ($e->kind)`.
 */
final class ProviderException extends AiException
{
    /**
     * @param string $message Never holds the key or the request body.
     * @param string $provider The provider name.
     * @param ErrorKind $kind The normalised failure.
     * @param int $httpStatus The HTTP status.
     * @param string|null $errorType The provider's raw error type, e.g. overloaded_error.
     * @param string|null $requestId The provider's request id.
     * @param float|null $retryAfter Seconds from the retry-after header.
     * @param Throwable|null $previous The cause.
     */
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly ErrorKind $kind,
        public readonly int $httpStatus,
        public readonly ?string $errorType = null,
        public readonly ?string $requestId = null,
        public readonly ?float $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
