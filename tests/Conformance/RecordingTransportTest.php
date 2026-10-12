<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Conformance;

use Ldkafka\Ai\Http\HttpRequest;
use Ldkafka\Ai\Http\HttpResponse;
use Ldkafka\Ai\Tests\Support\FixtureTransport;
use Ldkafka\Ai\Tests\Support\QueueTransport;
use Ldkafka\Ai\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * AI_RECORD writes fixtures that replay, with allow-listed headers only.
 */
final class RecordingTransportTest extends TestCase
{
    public function testSavedFixtureReplaysAndHoldsNoCredentials(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'fixture');
        try {
            $recorder = new RecordingTransport(new QueueTransport([
                new HttpResponse(200, ['Request-Id' => 'req_1', 'Set-Cookie' => 'x=y', 'Content-Type' => 'application/json'], '{"content":[],"input":{}}'),
                new HttpResponse(413, ['Content-Type' => 'text/html'], '<html>too large</html>'),
            ]));
            $request = new HttpRequest('POST', 'https://api.anthropic.com/v1/messages', [
                'authorization' => 'Bearer sk-live-secret',
                'x-api-key' => 'sk-live-secret',
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ], '{"model":"m","metadata":{},"messages":[]}');
            $recorder->send($request);
            $recorder->send($request);

            $recorder->save($path, ['scenario' => 'demo', 'provider' => 'anthropic', 'live' => false, 'model' => 'm']);

            $text = (string) file_get_contents($path);
            self::assertStringNotContainsString('sk-live-secret', $text);
            self::assertStringNotContainsString('Set-Cookie', $text);
            $fixture = FixtureTransport::load($path);
            self::assertSame(false, $fixture['synthetic']);
            self::assertSame(false, $fixture['live']);

            $replay = new FixtureTransport($fixture);
            self::assertSame('{"content":[],"input":{}}', $replay->send($request)->body);
            $second = $replay->send($request);
            self::assertSame(413, $second->status);
            self::assertSame('<html>too large</html>', $second->body);
            self::assertTrue($replay->exhausted());
        } finally {
            @unlink($path);
        }
    }
}
