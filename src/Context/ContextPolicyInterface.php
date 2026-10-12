<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Context;

use Ldkafka\Ai\Exception\ContextOverflowException;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Request;

/**
 * Decides what history a run sends. The Runner applies it once, when a run starts.
 */
interface ContextPolicyInterface
{
    /**
     * The request to send.
     *
     * @throws ContextOverflowException When even the newest turn does not fit.
     */
    public function apply(Request $request, ModelProfile $model): Request;
}
