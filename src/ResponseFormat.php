<?php

declare(strict_types=1);

namespace Ldkafka\Ai;

/**
 * Ask for JSON matching a schema. v0.1 maps it to the provider; hydration to typed objects comes later.
 */
final readonly class ResponseFormat
{
    /**
     * @param string $name A name for the schema.
     * @param array<string, mixed> $schema A JSON Schema of type object.
     */
    private function __construct(public string $name, public array $schema)
    {
    }

    /**
     * A JSON Schema answer format.
     *
     * @param array<string, mixed> $schema
     */
    public static function jsonSchema(array $schema, string $name = 'response'): self
    {
        return new self($name, $schema);
    }
}
