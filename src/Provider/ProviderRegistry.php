<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Provider;

use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Pipeline\HandlerInterface;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;

/**
 * Providers by name; the last step of the pipeline dispatches on the model's provider.
 */
final class ProviderRegistry implements HandlerInterface
{
    /**
     * @param array<string, ProviderInterface> $providers
     */
    public function __construct(private array $providers)
    {
    }

    /**
     * A provider by name.
     *
     * @throws ConfigurationException When the name is not registered.
     */
    public function get(string $name): ProviderInterface
    {
        if (!isset($this->providers[$name])) {
            throw new ConfigurationException('Provider "' . $name . '" is not registered.');
        }

        return $this->providers[$name];
    }

    /**
     * Send the request through the model's provider.
     */
    public function handle(Request $request, ModelProfile $model): Response
    {
        return $this->get($model->provider)->send($request, $model);
    }
}
