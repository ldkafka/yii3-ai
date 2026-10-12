<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Model;

/**
 * What a model can do. A request that needs a capability the model lacks fails before any HTTP call.
 */
enum Capability: string
{
    case Tools = 'tools';
    case Vision = 'vision';
    case Pdf = 'pdf';
    case JsonSchema = 'jsonSchema';
    case PromptCache = 'promptCache';
    case ServerState = 'serverState';
    case Thinking = 'thinking';
}
