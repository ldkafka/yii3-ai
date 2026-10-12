<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Context;

use InvalidArgumentException;
use Ldkafka\Ai\Exception\AiException;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\Role;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;
use Ldkafka\Ai\Tool\Runner;
use Ldkafka\Ai\Tool\RunResult;

use function count;
use function is_string;

/**
 * Persistent conversations: a store plus a Runner.
 *
 * The user message is stored before the run, so it survives a failed call. Tool calls left without
 * results by a run that died are answered "not executed: run interrupted" ahead of the new text,
 * because the provider rejects a tool call without a result. Each message the run appends is
 * stored as it is appended, with its Response.
 *
 * Chat assumes one writer per conversation: two concurrent send() calls on the same id can
 * interleave their rows in the store. Serialise sends per conversation (a lock or a queue).
 */
final class Chat
{
    public const INTERRUPTED = 'not executed: run interrupted';

    /**
     * @param Runner $runner Runs the model and the tools.
     * @param ConversationStoreInterface $store Holds the history.
     */
    public function __construct(private Runner $runner, private ConversationStoreInterface $store)
    {
    }

    /**
     * Send a user message and run until the model is done.
     *
     * @param string $conversationId The caller's id, 1 to 64 characters.
     * @param Message|string $message A user message or its text.
     * @param Request $template Model, system, cache hints, maxTokens and providerOptions; no messages.
     * @throws InvalidArgumentException When the template has messages or the message is not a user message.
     * @throws AiException When a model call fails.
     */
    public function send(string $conversationId, Message|string $message, Request $template = new Request()): RunResult
    {
        if ($template->messages !== []) {
            throw new InvalidArgumentException('The Chat template must not carry messages.');
        }
        $message = is_string($message) ? Message::user($message) : $message;
        if ($message->role !== Role::User) {
            throw new InvalidArgumentException('Chat::send() takes a user message.');
        }

        $history = $this->store->load($conversationId);
        $last = $history === [] ? null : $history[count($history) - 1];
        if ($last !== null && $last->role === Role::Assistant && $last->toolCalls() !== []) {
            $results = [];
            foreach ($last->toolCalls() as $call) {
                $results[] = ToolResult::text($call->id, $call->name, self::INTERRUPTED, true);
            }
            $message = new Message(Role::User, [...$results, ...$message->parts]);
        }

        $this->store->append($conversationId, $message);

        return $this->runner->run(
            $template->withMessages([...$history, $message]),
            function (Message $appended, ?Response $response) use ($conversationId): void {
                $this->store->append($conversationId, $appended, $response);
            },
        );
    }
}
