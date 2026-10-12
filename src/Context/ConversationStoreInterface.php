<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Context;

use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Response;

/**
 * Stored conversation history: the source of truth. Conversation ids are the caller's, 1 to 64 characters.
 */
interface ConversationStoreInterface
{
    /**
     * Append a message. An assistant message may carry the Response it came from (model, usage, cost).
     *
     * @throws \InvalidArgumentException When the conversation id is empty or longer than 64 characters.
     */
    public function append(string $conversationId, Message $message, ?Response $response = null): void;

    /**
     * The messages in order; [] for an unknown id.
     *
     * @return list<Message>
     */
    public function load(string $conversationId): array;

    /**
     * Delete a conversation; an unknown id is a no-op.
     */
    public function delete(string $conversationId): void;
}
