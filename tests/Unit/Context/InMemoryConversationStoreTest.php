<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Context;

use InvalidArgumentException;
use Ldkafka\Ai\Context\InMemoryConversationStore;
use Ldkafka\Ai\Message\Message;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class InMemoryConversationStoreTest extends TestCase
{
    public function testAppendLoadDelete(): void
    {
        $store = new InMemoryConversationStore();
        $first = Message::user('a');
        $second = Message::assistant('b');

        $store->append('c1', $first);
        $store->append('c1', $second);
        $store->append('c2', Message::user('other'));

        self::assertSame([$first, $second], $store->load('c1'));
        self::assertSame([], $store->load('unknown'));
        $store->delete('c1');
        self::assertSame([], $store->load('c1'));
        self::assertCount(1, $store->load('c2'));
    }

    public function testConversationIdLength(): void
    {
        $store = new InMemoryConversationStore();
        $store->append(str_repeat('a', 64), Message::user('ok'));

        $this->expectException(InvalidArgumentException::class);
        $store->append(str_repeat('a', 65), Message::user('too long'));
    }

    public function testEmptyConversationId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new InMemoryConversationStore())->append('', Message::user('x'));
    }
}
