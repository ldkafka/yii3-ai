<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Exception;

use Throwable;

/**
 * A remote MCP server or the exchange with it failed. Never carries the token, arguments or results.
 */
final class McpException extends AiException
{
    /**
     * @param string $message What failed.
     * @param int|null $httpStatus The HTTP status when the server answered with one.
     * @param int|null $rpcCode The JSON-RPC error code when the server sent one.
     * @param Throwable|null $previous The cause.
     * @param float|null $retryAfter Seconds from the server's retry-after header on a non-2xx answer.
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $rpcCode = null,
        ?Throwable $previous = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
