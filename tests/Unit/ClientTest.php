<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit;

use Ldkafka\Ai\Client;
use Ldkafka\Ai\Exception\CapabilityException;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Exception\ErrorKind;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Message\Document;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Model\Capability;
use Ldkafka\Ai\Model\ModelCatalog;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Model\Price;
use Ldkafka\Ai\Pipeline\RetryMiddleware;
use Ldkafka\Ai\Provider\ProviderRegistry;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\ResponseFormat;
use Ldkafka\Ai\StopReason;
use Ldkafka\Ai\Tests\Support\ArrayLogger;
use Ldkafka\Ai\Tests\Support\FakeProvider;
use Ldkafka\Ai\Tool\ToolChoice;
use Ldkafka\Ai\Tool\ToolSpec;
use Ldkafka\Ai\Usage;
use Ldkafka\Ai\Provider\Anthropic\AnthropicProvider;
use Ldkafka\Ai\Tests\Support\QueueTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private FakeProvider $provider;

    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->provider = new FakeProvider();
        $this->logger = new ArrayLogger();
    }

    public function testCompleteAddsCostLatencyAndLogsOneLine(): void
    {
        $this->provider->push(FakeProvider::response(Message::assistant('Hi'), StopReason::Complete, new Usage(1_000_000, 1_000_000)));

        $response = $this->client()->complete(new Request([Message::user('Hello')]));

        self::assertSame('Hi', $response->text());
        self::assertNotNull($response->cost);
        self::assertEqualsWithDelta(3.0, $response->cost->total(), 1e-9);
        self::assertGreaterThanOrEqual(0, $response->latencyMs);
        self::assertCount(1, $this->logger->records);
        self::assertSame('info', $this->logger->records[0]['level']);
        self::assertStringContainsString('1 attempt(s)', $this->logger->records[0]['message']);
        self::assertStringNotContainsString('Hello', $this->logger->records[0]['message']);
    }

    public function testDefaultModelAndUnpricedModel(): void
    {
        $this->provider->push(FakeProvider::response(Message::assistant('a')));
        $this->provider->push(FakeProvider::response(Message::assistant('b')));
        $client = $this->client();

        self::assertNotNull($client->complete(new Request([Message::user('x')]))->cost);
        self::assertNull($client->complete(new Request([Message::user('x')], model: 'cheap'))->cost);
        self::assertSame('full', $client->model()->name);
    }

    public function testUnknownModel(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client()->complete(new Request([Message::user('x')], model: 'nope'));
    }

    /**
     * @return iterable<string, array{Request, list<Capability>}>
     */
    public static function needs(): iterable
    {
        $spec = new ToolSpec('t', 'd', ['type' => 'object']);
        yield 'tools' => [new Request([Message::user('x')], tools: [$spec]), [Capability::Tools]];
        yield 'tool choice' => [new Request([Message::user('x')], toolChoice: ToolChoice::none()), [Capability::Tools]];
        yield 'tool call in history' => [new Request([Message::assistant(new ToolCall('a', 'b'))]), [Capability::Tools]];
        yield 'image inside a tool result' => [
            new Request([Message::user(new ToolResult('a', 'b', [new Image('image/png', 'AAAA')]))]),
            [Capability::Tools, Capability::Vision],
        ];
        yield 'pdf' => [new Request([Message::user(Document::fromUrl('https://example.com/a.pdf'))]), [Capability::Pdf]];
        yield 'json schema' => [new Request([Message::user('x')], responseFormat: ResponseFormat::jsonSchema([])), [Capability::JsonSchema]];
        yield 'requires' => [new Request([Message::user('x')], requires: [Capability::ServerState, Capability::Thinking]), [Capability::ServerState, Capability::Thinking]];
    }

    /**
     * @param list<Capability> $missing
     */
    #[DataProvider('needs')]
    public function testMissingCapabilitiesFailBeforeAnyCall(Request $request, array $missing): void
    {
        try {
            $this->client()->complete($request->withModel('cheap'));
            self::fail('Expected CapabilityException');
        } catch (CapabilityException $e) {
            self::assertSame($missing, $e->missing);
            self::assertStringContainsString('"cheap"', $e->getMessage());
        }
        self::assertSame([], $this->provider->requests);
    }

    public function testFailureIsLoggedWithAttemptsAndRethrown(): void
    {
        $error = new ProviderException('Anthropic API error (HTTP 529 overloaded_error): Overloaded', 'fake', ErrorKind::Overloaded, 529, 'overloaded_error', 'req_1');
        $this->provider->push($error);
        $this->provider->push($error);
        $client = new Client(
            $this->catalog(),
            new ProviderRegistry(['fake' => $this->provider]),
            [new RetryMiddleware(sleep: static function (int $ms): void {
            })],
            logger: $this->logger,
        );
        $this->provider->push($error);

        try {
            $client->complete(new Request([Message::user('x')]));
            self::fail('Expected ProviderException');
        } catch (ProviderException $e) {
            self::assertSame($error, $e);
        }
        self::assertCount(3, $this->provider->requests);
        self::assertTrue($this->logger->has('warning', '3 attempt(s)'));
        self::assertTrue($this->logger->has('warning', 'overloaded HTTP 529 overloaded_error [request req_1]'));
    }

    private function client(): Client
    {
        return new Client($this->catalog(), new ProviderRegistry(['fake' => $this->provider]), logger: $this->logger);
    }

    private function catalog(): ModelCatalog
    {
        return new ModelCatalog([
            'full' => new ModelProfile('full', 'fake', 'full-1', Capability::cases(), 1000, 100, 100, new Price(1.0, 2.0)),
            'cheap' => new ModelProfile('cheap', 'fake', 'cheap-1', [], 1000, 100, 100),
        ], 'full');
    }

    public function testUnencodableRequestBecomesConfigurationException(): void
    {
        $transport = new QueueTransport();
        $client = new Client(
            new ModelCatalog(['m' => new ModelProfile('m', 'anthropic', 'm', Capability::cases(), 1000, 100, 100)], 'm'),
            new ProviderRegistry(['anthropic' => new AnthropicProvider('k', $transport)]),
        );

        try {
            $client->complete(new Request([Message::user('x')], tools: [new ToolSpec('t', 'd', ['type' => 'object', 'maximum' => INF])]));
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
        self::assertSame([], $transport->requests);
    }
}
