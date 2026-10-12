<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Http;

use Ldkafka\Ai\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function implode;

/**
 * Any PSR-18 client as the transport. Timeouts and redirects are that client's own: Guzzle and the
 * Symfony client follow redirects by default, so turn them off in the client you inject (only
 * CurlTransport guarantees no redirects). A client exception cannot tell whether the request was
 * sent, so it is reported as a failure before sending. Needs psr/http-client and psr/http-factory,
 * which this package only suggests.
 */
final class Psr18Transport implements TransportInterface
{
    /**
     * @param ClientInterface $client The PSR-18 client.
     * @param RequestFactoryInterface $requests A PSR-17 request factory.
     * @param StreamFactoryInterface $streams A PSR-17 stream factory.
     */
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
    ) {
    }

    /**
     * Send one request through the PSR-18 client.
     *
     * @throws TransportException When the client throws.
     */
    public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
    {
        $psrRequest = $this->requests->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        if ($request->body !== '') {
            $psrRequest = $psrRequest->withBody($this->streams->createStream($request->body));
        }

        try {
            $response = $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $e) {
            $uri = $psrRequest->getUri();
            throw new TransportException(
                'HTTP request to ' . $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath() . ' failed: ' . $e->getMessage(),
                false,
                false,
                $e,
            );
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return new HttpResponse($response->getStatusCode(), $headers, (string) $response->getBody());
    }
}
