<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Context;

use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Response;

/**
 * Conversations in process memory: tests, CLI scripts, queue jobs.
 */
final class InMemoryConversationStore implements ConversationStoreInterface
{
    /** @var array<string, list<Message>> */
    private array $conversations = [];

    /**
     * Append a message; the Response is not kept.
     */
    public function append(string $conversationId, Message $message, ?Response $response = null): void
    {
        ConversationId::check($conversationId);
        $this->conversations[$conversationId][] = $message;
    }

    /**
     * The messages in order.
     *
     * @return list<Message>
     */
    public function load(string $conversationId): array
    {
        return $this->conversations[$conversationId] ?? [];
    }

    /**
     * Forget a conversation.
     */
    public function delete(string $conversationId): void
    {
        unset($this->conversations[$conversationId]);
    }
}
