<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Message;

use InvalidArgumentException;
use JsonException;
use Ldkafka\Ai\Internal\Json;
use stdClass;

/**
 * A block the core does not model (for example a server-tool result enabled through
 * providerOptions). The JSON is echoed verbatim to its provider only.
 */
final readonly class ProviderPart implements Part
{
    /**
     * @param string $provider The provider that produced the block.
     * @param string $json The block as the provider sent it: a JSON object.
     * @throws InvalidArgumentException When $json is not a JSON object.
     */
    public function __construct(public string $provider, public string $json)
    {
        try {
            $decoded = Json::decodeObjects($json);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('A provider block is not valid JSON.', 0, $e);
        }
        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('A provider block must be a JSON object.');
        }
    }

    /**
     * @return array{type: 'provider', provider: string, json: string}
     */
    public function toArray(): array
    {
        return ['type' => 'provider', 'provider' => $this->provider, 'json' => $this->json];
    }
}
