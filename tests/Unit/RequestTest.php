<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit;

use Ldkafka\Ai\CacheHint;
use Ldkafka\Ai\CacheScope;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Model\Capability;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\ResponseFormat;
use Ldkafka\Ai\Tool\ToolChoice;
use Ldkafka\Ai\Tool\ToolSpec;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testWithersReturnCopiesAndKeepEverythingElse(): void
    {
        $spec = new ToolSpec('t', 'd', ['type' => 'object']);
        $request = new Request(
            [Message::user('a')],
            model: 'm',
            system: 's',
            tools: [$spec],
            toolChoice: ToolChoice::none(),
            responseFormat: ResponseFormat::jsonSchema(['type' => 'object'], 'answer'),
            maxTokens: 10,
            temperature: 1.0,
            stop: ['x'],
            metadata: ['user_id' => 'u'],
            cache: [new CacheHint(CacheScope::System)],
            requires: [Capability::Thinking],
            providerOptions: ['anthropic' => ['k' => 'v']],
        );

        $more = $request->withMessage(Message::assistant('b'));
        self::assertCount(1, $request->messages);
        self::assertCount(2, $more->messages);
        self::assertSame('s', $more->system);
        self::assertSame(10, $more->maxTokens);
        self::assertSame(['x'], $more->stop);
        self::assertSame(['anthropic' => ['k' => 'v']], $more->providerOptions);
        self::assertSame('answer', $more->responseFormat?->name);

        self::assertSame([], $request->withMessages([])->messages);
        self::assertSame('other', $request->withModel('other')->model);
        self::assertSame([], $request->withTools([])->tools);
        self::assertSame([$spec], $request->tools);
    }

    public function testListsAreReindexed(): void
    {
        $request = new Request([3 => Message::user('a')], stop: [5 => 'x']);

        self::assertArrayHasKey(0, $request->messages);
        self::assertSame(['x'], $request->stop);
    }

    public function testNonFiniteTemperatureIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Request(temperature: NAN);
    }

    public function testNonFiniteProviderOptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Request(providerOptions: ['anthropic' => ['output_config' => ['weights' => [1.0, INF]]]]);
    }
}
