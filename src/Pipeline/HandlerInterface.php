<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Pipeline;

use Ldkafka\Ai\Exception\AiException;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;

/**
 * The next step of the call pipeline.
 */
interface HandlerInterface
{
    /**
     * Handle the request for a resolved model.
     *
     * @throws AiException
     */
    public function handle(Request $request, ModelProfile $model): Response;
}
