<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Internal;

use InvalidArgumentException;
use JsonException;
use stdClass;

use function array_is_list;
use function get_object_vars;
use function is_finite;
use function is_float;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * JSON helpers shared by the mapper, the stores and the MCP client.
 *
 * One set of encode flags, a decode that keeps empty JSON objects as `{}`, and narrowing readers
 * for decoded arrays.
 *
 * @internal Not covered by semver.
 */
final class Json
{
    public const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    private const DEPTH = 512;

    /**
     * Encode a value with the package's flags.
     *
     * @throws JsonException When the value cannot be encoded.
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS, self::DEPTH);
    }

    /**
     * Decode JSON into PHP arrays.
     *
     * @throws JsonException When the text is not valid JSON.
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, true, self::DEPTH, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode JSON with every object kept as a {@see stdClass}.
     *
     * @throws JsonException When the text is not valid JSON.
     */
    public static function decodeObjects(string $json): mixed
    {
        return json_decode($json, false, self::DEPTH, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode JSON into arrays, keeping each empty JSON object as an empty {@see stdClass}, so
     * re-encoding gives `{}` again instead of `[]`.
     *
     * @throws JsonException When the text is not valid JSON.
     */
    public static function decodeKeepEmptyObjects(string $json): mixed
    {
        return self::objectsToArrays(self::decodeObjects($json));
    }

    /**
     * Turn decoded objects into arrays, leaving as {@see stdClass} the objects an array would
     * re-encode as a JSON array: empty ones and ones whose keys are 0, 1, 2, ... in order.
     */
    public static function objectsToArrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $vars = get_object_vars($value);
            if ($vars === [] || array_is_list($vars)) {
                $object = new stdClass();
                foreach ($vars as $key => $item) {
                    $object->{(string) $key} = self::objectsToArrays($item);
                }

                return $object;
            }
            $value = $vars;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::objectsToArrays($item);
            }

            return $out;
        }

        return $value;
    }

    /**
     * Reject NaN and infinite floats anywhere in a value, which JSON cannot carry.
     *
     * @throws InvalidArgumentException Naming $what.
     */
    public static function assertFinite(mixed $value, string $what): void
    {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException($what . ' must not hold NaN or an infinite number.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertFinite($item, $what);
            }
        }
    }

    /**
     * Read a string field; anything else gives null.
     *
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Read an integer field; anything else gives 0.
     *
     * @param array<mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    /**
     * Read an array field; anything else gives an empty array.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
