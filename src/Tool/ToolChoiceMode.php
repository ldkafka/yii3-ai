<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tool;

/**
 * Whether the model may, must or must not call a tool.
 */
enum ToolChoiceMode: string
{
    /** The model decides. */
    case Auto = 'auto';
    /** The model must not call a tool. */
    case None = 'none';
    /** The model must call at least one tool. */
    case Required = 'required';
    /** The model must call the named tool. */
    case Tool = 'tool';
}
