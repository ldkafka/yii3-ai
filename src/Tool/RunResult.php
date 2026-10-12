<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tool;

use Ldkafka\Ai\Cost;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Response;
use Ldkafka\Ai\Usage;

/**
 * What a run produced.
 */
final readonly class RunResult
{
    /**
     * @param Response $response The last model response.
     * @param list<Message> $messages Every message the run appended, in order.
     * @param Usage $usage Summed over the steps.
     * @param Cost|null $cost Summed over the steps; null when the model has no price.
     * @param int $steps Model calls made.
     * @param RunStatus $status Why the run ended.
     */
    public function __construct(
        public Response $response,
        public array $messages,
        public Usage $usage,
        public ?Cost $cost,
        public int $steps,
        public RunStatus $status,
    ) {
    }

    /**
     * The last response's text.
     */
    public function text(): string
    {
        return $this->response->text();
    }
}
