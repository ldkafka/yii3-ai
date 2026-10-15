<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Http;

use function is_numeric;
use function strtolower;
use function trim;

/**
 * One HTTP answer. Header names are stored in lower case.
 */
final readonly class HttpResponse
{
    /** @var array<string, string> */
    public array $headers;

    /**
     * @param int $status The HTTP status.
     * @param array<string, string> $headers Header values by name (any case).
     * @param string $body The body.
     */
    public function __construct(public int $status, array $headers, public string $body)
    {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }
        $this->headers = $normalised;
    }

    /**
     * A header value by name, any case; null when absent.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Seconds from a numeric retry-after header; null when it is absent, a date or negative.
     */
    public function retryAfter(): ?float
    {
        $value = $this->header('retry-after');
        if ($value === null || !is_numeric(trim($value))) {
            return null;
        }
        $seconds = (float) trim($value);

        return $seconds >= 0 ? $seconds : null;
    }
}
