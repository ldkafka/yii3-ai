<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Http;

use Closure;
use CurlHandle;
use Ldkafka\Ai\Exception\TransportException;

use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_reset;
use function curl_setopt_array;
use function explode;
use function is_string;
use function parse_url;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * The default transport: ext-curl on one reused handle, so the TLS connection stays open between
 * calls. TLS verification on, TCP keep-alive on, no redirects. Timeouts in seconds, per request.
 * An optional abort callback cancels a request in flight.
 */
final class CurlTransport implements TransportInterface
{
    private ?CurlHandle $handle = null;

    /**
     * @param float $timeout Whole-request timeout in seconds.
     * @param float $connectTimeout Connect timeout in seconds.
     * @param (Closure(): bool)|null $abort Called while a request runs (about once a second, idle or
     *     not); returning true cancels it with a TransportException whose isCancelled() is true.
     */
    public function __construct(
        private float $timeout = 600.0,
        private float $connectTimeout = 10.0,
        private ?Closure $abort = null,
    ) {
    }

    /**
     * Send one request on the shared handle.
     *
     * @throws TransportException When no HTTP answer arrived.
     */
    public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
    {
        $url = $request->url;
        $method = strtoupper($request->method);
        if ($url === '' || $method === '') {
            throw new TransportException('An HTTP request needs a method and a URL.');
        }
        $handle = $this->handle();

        $headerLines = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return $length;
            },
        ];
        if ($request->body !== '') {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        if ($this->abort !== null) {
            $abort = $this->abort;
            $options[CURLOPT_NOPROGRESS] = false;
            $options[CURLOPT_XFERINFOFUNCTION] = static fn($curl, int $downTotal, int $down, int $upTotal, int $up): int => $abort() ? 1 : 0;
        }
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        if (!is_string($body)) {
            $errno = curl_errno($handle);
            // Once the request was sent (pre-transfer done), or the server dropped the connection
            // (52 empty reply, 56 receive error), the call may have run on the server. A timeout
            // (28) then is a read timeout; before that it is a connect failure.
            $sent = (float) curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME) > 0.0
                || $errno === CURLE_GOT_NOTHING
                || $errno === CURLE_RECV_ERROR;
            $message = sprintf('HTTP request to %s failed: %s (curl %d)', self::safeUrl($request->url), curl_error($handle), $errno);
            $this->handle = null;

            throw new TransportException(
                $message,
                $errno === CURLE_OPERATION_TIMEDOUT && $sent,
                $sent,
                cancelled: $errno === CURLE_ABORTED_BY_CALLBACK,
            );
        }

        /** @var array<string, string> $responseHeaders */
        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $body);
    }

    /**
     * The shared handle, reset between requests (which keeps its connection cache).
     *
     * @throws TransportException When curl cannot create a handle.
     */
    private function handle(): CurlHandle
    {
        if ($this->handle !== null) {
            curl_reset($this->handle);

            return $this->handle;
        }
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('Cannot create a curl handle.');
        }

        return $this->handle = $handle;
    }

    /**
     * The URL without credentials, query or fragment.
     */
    private static function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return '(invalid URL)';
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '');
    }
}
