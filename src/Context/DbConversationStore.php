<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Context;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Response;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Conversations in a database table through yiisoft/db (the table of
 * {@see \Ldkafka\Ai\Migration\M261012000000CreateAiMessageTable}). Needs yiisoft/db, which this
 * package only suggests.
 */
final class DbConversationStore implements ConversationStoreInterface
{
    /**
     * @param ConnectionInterface $db The connection.
     * @param string $table The table name.
     */
    public function __construct(private ConnectionInterface $db, private string $table = '{{%ai_message}}')
    {
    }

    /**
     * Insert one row. An assistant message with its Response also stores model, usage and cost.
     */
    public function append(string $conversationId, Message $message, ?Response $response = null): void
    {
        ConversationId::check($conversationId);
        $usage = $response?->usage;
        $this->db->createCommand()->insert($this->table, [
            'conversation_id' => $conversationId,
            'role' => $message->role->value,
            'parts' => Json::encode($message->toArray()['parts']),
            'provider' => $response?->provider,
            'model' => $response?->model,
            'stop_reason' => $response?->rawStopReason,
            'input_tokens' => $usage?->inputTokens,
            'output_tokens' => $usage?->outputTokens,
            'cache_read_tokens' => $usage?->cacheReadTokens,
            'cache_write_tokens' => $usage?->cacheWriteTokens,
            'reasoning_tokens' => $usage?->reasoningTokens,
            'cost_usd' => $response?->cost !== null ? sprintf('%.9F', $response->cost->total()) : null,
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
        ])->execute();
    }

    /**
     * The messages in insertion order.
     *
     * @return list<Message>
     * @throws InvalidArgumentException When a stored row cannot be read back.
     */
    public function load(string $conversationId): array
    {
        $rows = $this->db->createQuery()
            ->select(['role', 'parts'])
            ->from($this->table)
            ->where(['conversation_id' => $conversationId])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $messages = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('A stored message row is not an array.');
            }
            $parts = $row['parts'] ?? null;
            if (is_string($parts)) {
                try {
                    $parts = Json::decode($parts);
                } catch (JsonException $e) {
                    throw new InvalidArgumentException('A stored message has parts that are not valid JSON.', 0, $e);
                }
            }
            if (!is_array($parts)) {
                throw new InvalidArgumentException('A stored message has unreadable parts.');
            }
            $messages[] = Message::fromArray(['role' => $row['role'] ?? null, 'parts' => $parts]);
        }

        return $messages;
    }

    /**
     * Delete every row of a conversation.
     */
    public function delete(string $conversationId): void
    {
        $this->db->createCommand()->delete($this->table, ['conversation_id' => $conversationId])->execute();
    }
}
