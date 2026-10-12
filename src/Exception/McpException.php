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
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $rpcCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
