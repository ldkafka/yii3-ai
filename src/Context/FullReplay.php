<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Context;

use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Request;

/**
 * Sends the whole history (the default).
 */
final class FullReplay implements ContextPolicyInterface
{
    /**
     * The request, unchanged.
     */
    public function apply(Request $request, ModelProfile $model): Request
    {
        return $request;
    }
}
