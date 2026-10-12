<?php

declare(strict_types=1);

namespace Ldkafka\Ai;

use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\ToolCall;

/**
 * One model answer.
 */
final readonly class Response
{
    /**
     * @param Message $message The assistant message: append it to the conversation as is.
     * @param StopReason $stopReason Why the model stopped.
     * @param string $rawStopReason The provider's own value.
     * @param Usage $usage Token counts.
     * @param string $provider The provider name.
     * @param string $model The provider model id that answered.
     * @param string|null $id The provider's message id.
     * @param string|null $requestId The provider's request id header.
     * @param Cost|null $cost Null when the model has no price.
     * @param int $latencyMs Wall time of Client::complete(), retries included.
     * @param array<mixed> $raw The decoded provider body; a debugging aid, not covered by semver.
     */
    public function __construct(
        public Message $message,
        public StopReason $stopReason,
        public string $rawStopReason,
        public Usage $usage,
        public string $provider,
        public string $model,
        public ?string $id = null,
        public ?string $requestId = null,
        public ?Cost $cost = null,
        public int $latencyMs = 0,
        public array $raw = [],
    ) {
    }

    /**
     * The text of the answer.
     */
    public function text(): string
    {
        return $this->message->text();
    }

    /**
     * The tool calls of the answer.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return $this->message->toolCalls();
    }
}
