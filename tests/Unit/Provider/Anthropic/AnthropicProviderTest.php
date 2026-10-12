<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Provider\Anthropic;

use Ldkafka\Ai\Client;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Exception\ErrorKind;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Http\HttpResponse;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Provider\Anthropic\AnthropicProvider;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Tests\Support\QueueTransport;
use PHPUnit\Framework\TestCase;

final class AnthropicProviderTest extends TestCase
{
    private const KEY = 'sk-test-provider-key';

    public function testHeadersUrlAndBetas(): void
    {
        $transport = $this->transport(new HttpResponse(200, ['request-id' => 'req_1'], '{"content":[{"type":"text","text":"ok"}],"stop_reason":"end_turn","usage":{}}'));
        $provider = new AnthropicProvider(self::KEY, $transport, 'https://proxy.example.com/', '2023-06-01', ['b-1', 'b-2']);

        $response = $provider->send(new Request([Message::user('x')], providerOptions: ['anthropic' => ['betas' => ['b-2', 'b-3']]]), $this->model());

        self::assertSame('ok', $response->text());
        self::assertSame('req_1', $response->requestId);
        $sent = $transport->requests[0];
        self::assertSame('POST', $sent->method);
        self::assertSame('https://proxy.example.com/v1/messages', $sent->url);
        self::assertSame([
            'authorization' => 'Bearer ' . self::KEY,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'user-agent' => 'ldkafka-yii3-ai/' . Client::VERSION,
            'anthropic-beta' => 'b-1,b-2,b-3',
        ], $sent->headers);
        self::assertStringNotContainsString('betas', $sent->body);
    }

    public function testErrorStatusThrows(): void
    {
        $transport = $this->transport(new HttpResponse(401, [], '{"type":"error","error":{"type":"authentication_error","message":"invalid key"}}'));

        try {
            (new AnthropicProvider(self::KEY, $transport))->send(new Request([Message::user('secret prompt')]), $this->model());
            self::fail('Expected ProviderException');
        } catch (ProviderException $e) {
            self::assertSame(ErrorKind::Authentication, $e->kind);
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertStringNotContainsString('secret prompt', $e->getMessage());
        }
    }

    public function testMissingKeyFailsBeforeAnyRequest(): void
    {
        $transport = $this->transport(new HttpResponse(200, [], '{}'));

        try {
            (new AnthropicProvider('', $transport))->send(new Request([Message::user('x')]), $this->model());
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException) {
        }
        self::assertSame([], $transport->requests);
    }

    private function transport(HttpResponse $response): QueueTransport
    {
        return new QueueTransport([$response]);
    }

    private function model(): ModelProfile
    {
        return new ModelProfile('x', 'anthropic', 'claude-x', [], 1000, 128, 64);
    }

    public function testWorkspaceHeaderWhenSet(): void
    {
        $body = '{"content":[],"stop_reason":"end_turn","usage":{}}';
        $with = $this->transport(new HttpResponse(200, [], $body));
        $without = $this->transport(new HttpResponse(200, [], $body));

        (new AnthropicProvider(self::KEY, $with, workspaceId: 'wrkspc_01'))->send(new Request([Message::user('x')]), $this->model());
        (new AnthropicProvider(self::KEY, $without))->send(new Request([Message::user('x')]), $this->model());

        self::assertSame('wrkspc_01', $with->requests[0]->headers['anthropic-workspace-id'] ?? null);
        self::assertArrayNotHasKey('anthropic-workspace-id', $without->requests[0]->headers);
    }
}
