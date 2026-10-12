<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Model;

use InvalidArgumentException;
use Ldkafka\Ai\Internal\Json;

use function array_values;
use function in_array;

/**
 * One configured model.
 */
final readonly class ModelProfile
{
    /** @var list<Capability> */
    public array $capabilities;

    /**
     * @param string $name The catalog name used in Request::$model.
     * @param string $provider The provider name in the ProviderRegistry.
     * @param string $id The provider's model id.
     * @param array<Capability> $capabilities What the model can do.
     * @param int $maxContext The context window in tokens.
     * @param int $maxOutput The largest output the model allows.
     * @param int $maxTokens The output limit sent when a request sets none.
     * @param Price|null $price USD per million tokens; null = no cost estimate.
     * @param array<string, mixed> $options Provider payload defaults, merged before providerOptions.
     * @throws InvalidArgumentException When an option is NaN or infinite.
     */
    public function __construct(
        public string $name,
        public string $provider,
        public string $id,
        array $capabilities,
        public int $maxContext,
        public int $maxOutput,
        public int $maxTokens,
        public ?Price $price = null,
        public array $options = [],
    ) {
        Json::assertFinite($options, 'Model options');
        $this->capabilities = array_values($capabilities);
    }

    /**
     * Whether the model has a capability.
     */
    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
