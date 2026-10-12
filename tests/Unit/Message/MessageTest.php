<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Message;

use InvalidArgumentException;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Message\Document;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\ProviderPart;
use Ldkafka\Ai\Message\Role;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Message\Thinking;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testFactoriesTurnStringsIntoText(): void
    {
        $message = Message::user('Hello ', new Text('world'));

        self::assertSame(Role::User, $message->role);
        self::assertEquals([new Text('Hello '), new Text('world')], $message->parts);
        self::assertSame('Hello world', $message->text());
    }

    public function testToolCallsInOrder(): void
    {
        $first = new ToolCall('a', 'one');
        $second = new ToolCall('b', 'two');
        $message = Message::assistant('Checking.', $first, $second);

        self::assertSame([$first, $second], $message->toolCalls());
        self::assertSame('Checking.', $message->text());
    }

    public function testToolCallInUserMessageIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Message::user(new ToolCall('a', 'one'));
    }

    public function testThinkingInUserMessageIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Message::user(new Thinking('x', 'anthropic'));
    }

    public function testToolResultInAssistantMessageIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Message::assistant(ToolResult::text('a', 'one', 'x'));
    }

    public function testEmptyMessageIsAllowed(): void
    {
        self::assertSame([], Message::assistant()->parts);
    }

    public function testRoundTripKeepsEveryPartAndEmptyObjects(): void
    {
        $assistant = Message::assistant(
            new Thinking('Let me think.', 'anthropic', ['signature' => 'sig']),
            new Text('Calling.'),
            new ToolCall('toolu_1', 'search', '{"filters":{},"nested":{"deep":{}},"list":[]}'),
            new ProviderPart('anthropic', '{"type":"server_tool_use","input":{}}'),
        );
        $user = Message::user(
            new ToolResult('toolu_1', 'search', [new Text('found'), new Image('image/png', 'AAAA'), Document::fromUrl('https://example.com/a.pdf', 'A')], true),
            Image::fromUrl('https://example.com/a.png'),
            new Document('JVBERi0=', null, null),
        );

        foreach ([$assistant, $user] as $message) {
            $stored = Json::encode($message->toArray());
            $decoded = Json::decode($stored);
            self::assertIsArray($decoded);
            $restored = Message::fromArray($decoded);
            self::assertEquals($message, $restored);
        }

        $call = Message::fromArray($this->decoded($assistant))->toolCalls()[0];
        self::assertSame('{"filters":{},"nested":{"deep":{}},"list":[]}', $call->argumentsJson);
    }

    public function testFromArrayRejectsUnknownRole(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Message::fromArray(['role' => 'system', 'parts' => []]);
    }

    public function testFromArrayRejectsUnknownPartType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Message::fromArray(['role' => 'user', 'parts' => [['type' => 'audio']]]);
    }

    public function testFromArrayRejectsMissingField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Message::fromArray(['role' => 'user', 'parts' => [['type' => 'text']]]);
    }

    public function testFromArrayRejectsNonArrayPart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Message::fromArray(['role' => 'user', 'parts' => ['text']]);
    }

    public function testFromArrayRejectsToolResultWithForeignContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Message::fromArray(['role' => 'user', 'parts' => [[
            'type' => 'tool_result', 'callId' => 'a', 'name' => 'b', 'isError' => false,
            'content' => [['type' => 'provider', 'provider' => 'x', 'json' => '{}']],
        ]]]);
    }

    /**
     * @return array<mixed>
     */
    private function decoded(Message $message): array
    {
        $decoded = Json::decode(Json::encode($message->toArray()));
        self::assertIsArray($decoded);

        return $decoded;
    }
}
