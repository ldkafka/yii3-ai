<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Message;

use InvalidArgumentException;
use JsonException;
use Ldkafka\Ai\Internal\Json;
use stdClass;

use function is_array;

/**
 * A tool call the model made. The arguments are kept as JSON text, so `{}` stays `{}`.
 */
final readonly class ToolCall implements Part
{
    /**
     * @param string $id The provider's call id.
     * @param string $name The tool name.
     * @param string $argumentsJson A JSON object.
     * @throws InvalidArgumentException When $argumentsJson is not a JSON object.
     */
    public function __construct(public string $id, public string $name, public string $argumentsJson = '{}')
    {
        try {
            $decoded = Json::decodeObjects($argumentsJson);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Tool call arguments are not valid JSON.', 0, $e);
        }
        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('Tool call arguments must be a JSON object.');
        }
    }

    /**
     * Build a call from decoded arguments; an empty array becomes `{}`.
     *
     * @param array<string, mixed> $arguments
     */
    public static function of(string $id, string $name, array $arguments): self
    {
        return new self($id, $name, $arguments === [] ? '{}' : Json::encode($arguments));
    }

    /**
     * The decoded arguments.
     *
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        $decoded = Json::decode($this->argumentsJson);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @return array{type: 'tool_call', id: string, name: string, arguments: string}
     */
    public function toArray(): array
    {
        return ['type' => 'tool_call', 'id' => $this->id, 'name' => $this->name, 'arguments' => $this->argumentsJson];
    }
}
