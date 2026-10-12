<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Pipeline;

use Ldkafka\Ai\Exception\AiException;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;

/**
 * A step around the provider call (retry in v0.1). Shaped like PSR-15.
 */
interface MiddlewareInterface
{
    /**
     * Process the request, usually by calling $next.
     *
     * @throws AiException
     */
    public function process(Request $request, ModelProfile $model, HandlerInterface $next): Response;
}
