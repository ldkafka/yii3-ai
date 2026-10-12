<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Provider;

use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Exception\TransportException;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;

/**
 * One model API. One attempt per call, no retry (retries are middleware).
 */
interface ProviderInterface
{
    /**
     * Send the request to the model and return its answer.
     *
     * @throws ProviderException|TransportException|ConfigurationException
     */
    public function send(Request $request, ModelProfile $model): Response;
}
