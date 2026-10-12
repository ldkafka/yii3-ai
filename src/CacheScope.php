<?php

declare(strict_types=1);

namespace Ldkafka\Ai;

/**
 * Where a prompt-cache breakpoint goes.
 */
enum CacheScope: string
{
    /** After the last tool definition. */
    case Tools = 'tools';
    /** After the system prompt. */
    case System = 'system';
    /** After the last cacheable message block (the provider places it). */
    case Messages = 'messages';
}
