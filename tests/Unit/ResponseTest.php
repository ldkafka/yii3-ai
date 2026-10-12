<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit;

use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Response;
use Ldkafka\Ai\StopReason;
use Ldkafka\Ai\Usage;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testTextAndToolCallsComeFromTheMessage(): void
    {
        $call = new ToolCall('id', 'tool');
        $response = new Response(Message::assistant('Hi', $call), StopReason::ToolUse, 'tool_use', new Usage(), 'p', 'm');

        self::assertSame('Hi', $response->text());
        self::assertSame([$call], $response->toolCalls());
        self::assertNull($response->cost);
        self::assertSame(0, $response->latencyMs);
    }
}
