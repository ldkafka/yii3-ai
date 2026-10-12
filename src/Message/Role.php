<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Message;

/**
 * Who speaks in a message.
 */
enum Role: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
