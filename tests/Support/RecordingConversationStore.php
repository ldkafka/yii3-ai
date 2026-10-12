<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Support;

use Ldkafka\Ai\Context\ConversationStoreInterface;
use Ldkafka\Ai\Context\InMemoryConversationStore;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Response;

/**
 * An in-memory store that also keeps the Response passed with each append.
 */
final class RecordingConversationStore implements ConversationStoreInterface
{
    /** @var list<Response|null> */
    public array $responses = [];

    private InMemoryConversationStore $inner;

    /**
     * Start empty.
     */
    public function __construct()
    {
        $this->inner = new InMemoryConversationStore();
    }

    /**
     * Append and keep the Response.
     */
    public function append(string $conversationId, Message $message, ?Response $response = null): void
    {
        $this->responses[] = $response;
        $this->inner->append($conversationId, $message, $response);
    }

    /**
     * @return list<Message>
     */
    public function load(string $conversationId): array
    {
        return $this->inner->load($conversationId);
    }

    /**
     * Forget a conversation.
     */
    public function delete(string $conversationId): void
    {
        $this->inner->delete($conversationId);
    }
}
