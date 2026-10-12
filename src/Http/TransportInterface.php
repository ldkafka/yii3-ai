<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Http;

use Ldkafka\Ai\Exception\TransportException;

/**
 * Sends one HTTP request. Non-2xx answers are returned, not thrown.
 */
interface TransportInterface
{
    /**
     * Send the request and return the answer.
     *
     * @throws TransportException When no HTTP answer arrived (DNS, TLS, connect, timeout).
     */
    public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse;
}
