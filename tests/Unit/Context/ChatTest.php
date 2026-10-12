<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Context;

use InvalidArgumentException;
use Ldkafka\Ai\Client;
use Ldkafka\Ai\Context\Chat;
use Ldkafka\Ai\Exception\ErrorKind;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\Role;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Model\Capability;
use Ldkafka\Ai\Model\ModelCatalog;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Provider\ProviderRegistry;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\StopReason;
use Ldkafka\Ai\Tests\Support\CallbackTool;
use Ldkafka\Ai\Tests\Support\FakeProvider;
use Ldkafka\Ai\Tests\Support\RecordingConversationStore;
use Ldkafka\Ai\Tool\Runner;
use Ldkafka\Ai\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ChatTest extends TestCase
{
    private FakeProvider $provider;

    private RecordingConversationStore $store;

    protected function setUp(): void
    {
        $this->provider = new FakeProvider();
        $this->store = new RecordingConversationStore();
    }

    public function testStoresEachMessageAsItIsAppended(): void
    {
        $tool = new CallbackTool('lookup', static fn (array $a): string => 'Confirmed.');
        $this->provider->push(FakeProvider::response(Message::assistant(new ToolCall('c1', 'lookup')), StopReason::ToolUse));
        $this->provider->push(FakeProvider::response(Message::assistant('Your booking is confirmed.')));

        $result = $this->chat(new ToolRegistry([$tool]))->send('ticket-42', 'Where is booking 1234?', new Request(system: 'You are support.'));

        self::assertSame('Your booking is confirmed.', $result->text());
        $history = $this->store->load('ticket-42');
        self::assertCount(4, $history);
        self::assertSame([Role::User, Role::Assistant, Role::User, Role::Assistant], array_map(static fn (Message $m): Role => $m->role, $history));
        self::assertNull($this->store->responses[0]);
        self::assertNotNull($this->store->responses[1]);
        self::assertNull($this->store->responses[2]);
        self::assertNotNull($this->store->responses[3]);
        self::assertSame('You are support.', $this->provider->requests[0]->system);
    }

    public function testUserMessageSurvivesAFailedCall(): void
    {
        $this->provider->push(new ProviderException('down', 'fake', ErrorKind::Server, 500));

        try {
            $this->chat()->send('t', 'Hello');
            self::fail('Expected the provider error');
        } catch (ProviderException) {
        }

        self::assertCount(1, $this->store->load('t'));
    }

    public function testInterruptedToolCallsAreAnsweredBeforeTheNewText(): void
    {
        $this->store->append('t', Message::user('Look it up.'));
        $this->store->append('t', Message::assistant(new ToolCall('c1', 'lookup'), new ToolCall('c2', 'lookup')));
        $this->provider->push(FakeProvider::response(Message::assistant('Sorry, try again?')));

        $this->chat()->send('t', 'Any news?');

        $sent = $this->provider->requests[0]->messages;
        self::assertCount(3, $sent);
        $parts = $sent[2]->parts;
        self::assertCount(3, $parts);
        self::assertInstanceOf(ToolResult::class, $parts[0]);
        self::assertInstanceOf(ToolResult::class, $parts[1]);
        self::assertSame(['c1', 'c2'], [$parts[0]->callId, $parts[1]->callId]);
        self::assertTrue($parts[0]->isError);
        self::assertEquals([new Text(Chat::INTERRUPTED)], $parts[0]->content);
        self::assertEquals(new Text('Any news?'), $parts[2]);
        self::assertEquals($sent[2], $this->store->load('t')[2]);
    }

    public function testTemplateMustNotCarryMessages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->chat()->send('t', 'x', new Request([Message::user('y')]));
    }

    public function testOnlyUserMessages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->chat()->send('t', Message::assistant('x'));
    }

    private function chat(?ToolRegistry $tools = null): Chat
    {
        $client = new Client(
            new ModelCatalog(['m' => new ModelProfile('m', 'fake', 'm', Capability::cases(), 100_000, 100, 100)], 'm'),
            new ProviderRegistry(['fake' => $this->provider]),
        );

        return new Chat(new Runner($client, $tools ?? new ToolRegistry()), $this->store);
    }
}
