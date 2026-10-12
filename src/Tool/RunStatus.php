<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tool;

/**
 * Why a run ended.
 */
enum RunStatus: string
{
    /** The model stopped for a reason other than a tool call; see the response's stopReason. */
    case Completed = 'completed';
    /** maxSteps model calls were made. */
    case StepLimit = 'step_limit';
    /** The summed cost reached maxCostUsd. */
    case BudgetExhausted = 'budget_exhausted';
}
