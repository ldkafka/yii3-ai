<?php

declare(strict_types=1);

namespace Ldkafka\Ai;

/**
 * Why the model stopped, normalised across providers. The raw value is kept on the Response.
 */
enum StopReason: string
{
    case Complete = 'complete';
    case MaxTokens = 'max_tokens';
    case StopSequence = 'stop_sequence';
    case ToolUse = 'tool_use';
    /** The provider paused a long turn: send the message back to continue. */
    case Paused = 'paused';
    case Refused = 'refused';
    case ContextLimit = 'context_limit';
    /** An unknown value: see Response::$rawStopReason. */
    case Other = 'other';
}
