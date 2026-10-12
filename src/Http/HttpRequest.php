<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Http;

use function in_array;
use function strtolower;

/**
 * One HTTP request. Debug dumps redact the credential headers.
 */
final readonly class HttpRequest
{
    /** Headers whose values debug dumps never show. */
    public const SECRET_HEADERS = ['authorization', 'x-api-key', 'mcp-session-id'];

    /**
     * @param string $method The HTTP method.
     * @param string $url The full URL.
     * @param array<string, string> $headers Header values by name.
     * @param string $body The body; '' for none.
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    /**
     * The fields for var_dump() and print_r(), with credential header values redacted.
     *
     * @return array{method: string, url: string, headers: array<string, string>, body: string}
     */
    public function __debugInfo(): array
    {
        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[$name] = in_array(strtolower($name), self::SECRET_HEADERS, true) ? '[redacted]' : $value;
        }

        return ['method' => $this->method, 'url' => $this->url, 'headers' => $headers, 'body' => $this->body];
    }
}
