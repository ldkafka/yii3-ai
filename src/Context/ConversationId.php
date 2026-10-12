<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Context;

use InvalidArgumentException;

use function strlen;

/**
 * The conversation id rule shared by the stores.
 *
 * @internal
 */
final class ConversationId
{
    public const MAX_LENGTH = 64;

    /**
     * @throws InvalidArgumentException When the id is empty or longer than 64 bytes.
     */
    public static function check(string $conversationId): void
    {
        $length = strlen($conversationId);
        if ($length === 0 || $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A conversation id has 1 to 64 characters.');
        }
    }
}
