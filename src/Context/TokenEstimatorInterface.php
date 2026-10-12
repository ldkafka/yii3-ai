<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Context;

use Ldkafka\Ai\Message\Message;

/**
 * Offline token estimates for context policies.
 */
interface TokenEstimatorInterface
{
    /**
     * Estimated tokens of one message.
     */
    public function message(Message $message): int;

    /**
     * Estimated tokens of a text.
     */
    public function text(string $text): int;
}
