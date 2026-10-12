<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Support;

use Ldkafka\Ai\Http\HttpRequest;
use Ldkafka\Ai\Http\HttpResponse;
use Ldkafka\Ai\Http\TransportInterface;
use Ldkafka\Ai\Internal\Json;
use PHPUnit\Framework\Assert;
use stdClass;

use function count;
use function file_get_contents;
use function get_object_vars;
use function is_array;
use function is_int;
use function is_string;
use function ksort;
use function parse_url;
use function sprintf;
use function strtolower;

/**
 * Replays recorded exchanges. Each outgoing request is checked against the recorded one (method,
 * path, the recorded headers, absent headers, a JSON-equal body with objects kept as objects)
 * unless request checks are off (live-style runs).
 */
final class FixtureTransport implements TransportInterface
{
    /** @var list<array<string, mixed>> */
    private array $exchanges;

    /** @var list<HttpRequest> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $fixture The decoded fixture file.
     * @param bool $checkRequests Whether outgoing requests must equal the recorded ones.
     */
    public function __construct(private array $fixture, private bool $checkRequests = true)
    {
        $exchanges = [];
        foreach (Json::array($fixture, 'exchanges') as $exchange) {
            if (is_array($exchange)) {
                $exchanges[] = self::stringKeys($exchange);
            }
        }
        $this->exchanges = $exchanges;
    }

    /**
     * Load a fixture file.
     */
    public static function fromFile(string $path, bool $checkRequests = true): self
    {
        return new self(self::load($path), $checkRequests);
    }

    /**
     * Decode a fixture file.
     *
     * @return array<string, mixed>
     */
    public static function load(string $path): array
    {
        $json = file_get_contents($path);
        Assert::assertIsString($json, 'Cannot read fixture ' . $path);
        // Empty JSON objects stay objects, so a recorded {} is replayed and compared as {}.
        $data = Json::decodeKeepEmptyObjects($json);
        Assert::assertIsArray($data);

        return self::stringKeys($data);
    }

    /**
     * Replay the next exchange.
     */
    public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
    {
        $index = count($this->requests);
        $this->requests[] = $request;
        Assert::assertArrayHasKey($index, $this->exchanges, sprintf(
            'Fixture "%s" has %d exchange(s); request %d was not expected.',
            (Json::string($this->fixture, 'scenario') ?? '?'),
            count($this->exchanges),
            $index + 1,
        ));
        $exchange = $this->exchanges[$index];

        if ($this->checkRequests) {
            $this->check(self::stringKeys(Json::array($exchange, 'request')), $request, $index + 1);
        }

        $response = Json::array($exchange, 'response');
        $headers = [];
        foreach (Json::array($response, 'headers') as $name => $value) {
            $headers[(string) $name] = is_string($value) ? $value : (string) Json::encode($value);
        }
        $body = Json::string($response, 'bodyText');
        if ($body === null) {
            $body = isset($response['body']) ? Json::encode($response['body']) : '';
        }
        $status = $response['status'] ?? 200;

        return new HttpResponse(is_int($status) ? $status : 200, $headers, $body);
    }

    /**
     * Whether every exchange was replayed.
     */
    public function exhausted(): bool
    {
        return count($this->requests) === count($this->exchanges);
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function check(array $expected, HttpRequest $request, int $number): void
    {
        $label = sprintf('request %d of fixture "%s"', $number, (Json::string($this->fixture, 'scenario') ?? '?'));
        Assert::assertSame(Json::string($expected, 'method') ?? 'POST', $request->method, 'Method of ' . $label);
        $path = parse_url($request->url, PHP_URL_PATH);
        Assert::assertSame(Json::string($expected, 'path'), is_string($path) ? $path : '', 'Path of ' . $label);

        $sent = [];
        foreach ($request->headers as $name => $value) {
            $sent[strtolower($name)] = $value;
        }
        foreach (Json::array($expected, 'headers') as $name => $value) {
            Assert::assertSame($value, $sent[strtolower((string) $name)] ?? null, 'Header "' . $name . '" of ' . $label);
        }
        foreach (Json::array($expected, 'absentHeaders') as $name) {
            Assert::assertArrayNotHasKey(strtolower((string) (is_string($name) ? $name : '')), $sent, 'Header that must be absent in ' . $label);
        }

        if (isset($expected['body'])) {
            $want = self::canonical(Json::decodeObjects(Json::encode($expected['body'])));
            $got = self::canonical(Json::decodeObjects($request->body));
            Assert::assertSame($want, $got, 'Body of ' . $label);
        }
    }

    /**
     * JSON text with object keys sorted, so key order does not matter and `{}` differs from `[]`.
     */
    private static function canonical(mixed $value): string
    {
        return Json::encode(self::sortKeys($value));
    }

    /**
     * Sort object keys recursively; lists keep their order.
     */
    private static function sortKeys(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $vars = get_object_vars($value);
            ksort($vars);
            $out = new stdClass();
            foreach ($vars as $key => $item) {
                $out->{$key} = self::sortKeys($item);
            }

            return $out;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::sortKeys($item);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
