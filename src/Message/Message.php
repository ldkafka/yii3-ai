<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Message;

use InvalidArgumentException;
use Ldkafka\Ai\Internal\Json;

use function array_map;
use function array_values;
use function implode;
use function is_array;
use function is_bool;
use function is_string;

/**
 * One turn: a role and its parts.
 *
 * ToolCall and Thinking appear only in assistant messages, ToolResult only in user messages.
 */
final readonly class Message
{
    /** @var list<Part> */
    public array $parts;

    /**
     * @param Role $role Who speaks.
     * @param array<Part> $parts The content blocks, in order; may be empty.
     * @throws InvalidArgumentException When a part does not fit the role.
     */
    public function __construct(public Role $role, array $parts)
    {
        foreach ($parts as $part) {
            if ($role === Role::User && ($part instanceof ToolCall || $part instanceof Thinking)) {
                throw new InvalidArgumentException('ToolCall and Thinking parts belong in assistant messages.');
            }
            if ($role === Role::Assistant && $part instanceof ToolResult) {
                throw new InvalidArgumentException('ToolResult parts belong in user messages.');
            }
        }
        $this->parts = array_values($parts);
    }

    /**
     * A user message; strings become Text parts.
     */
    public static function user(string|Part ...$parts): self
    {
        return new self(Role::User, self::parts($parts));
    }

    /**
     * An assistant message; strings become Text parts.
     */
    public static function assistant(string|Part ...$parts): self
    {
        return new self(Role::Assistant, self::parts($parts));
    }

    /**
     * The Text parts joined, without separators.
     */
    public function text(): string
    {
        $texts = [];
        foreach ($this->parts as $part) {
            if ($part instanceof Text) {
                $texts[] = $part->text;
            }
        }

        return implode('', $texts);
    }

    /**
     * The tool calls, in order.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        $calls = [];
        foreach ($this->parts as $part) {
            if ($part instanceof ToolCall) {
                $calls[] = $part;
            }
        }

        return $calls;
    }

    /**
     * The neutral storage form.
     *
     * @return array{role: string, parts: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'parts' => array_map(static fn (Part $part): array => $part->toArray(), $this->parts),
        ];
    }

    /**
     * Read a message written by {@see toArray()}.
     *
     * @param array<mixed> $data
     * @throws InvalidArgumentException When the data is not a valid message.
     */
    public static function fromArray(array $data): self
    {
        $role = Role::tryFrom(Json::string($data, 'role') ?? '');
        if ($role === null) {
            throw new InvalidArgumentException('A stored message needs a role of user or assistant.');
        }
        $parts = [];
        foreach (Json::array($data, 'parts') as $part) {
            if (!is_array($part)) {
                throw new InvalidArgumentException('A stored part must be an array.');
            }
            $parts[] = self::partFromArray($part);
        }

        return new self($role, $parts);
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidArgumentException
     */
    private static function partFromArray(array $data): Part
    {
        $type = Json::string($data, 'type');

        return match ($type) {
            'text' => new Text(self::requireString($data, 'text')),
            'image' => new Image(Json::string($data, 'mediaType') ?? '', Json::string($data, 'data'), Json::string($data, 'url')),
            'document' => new Document(Json::string($data, 'data'), Json::string($data, 'url'), Json::string($data, 'title')),
            'tool_call' => new ToolCall(self::requireString($data, 'id'), self::requireString($data, 'name'), self::requireString($data, 'arguments')),
            'tool_result' => new ToolResult(
                self::requireString($data, 'callId'),
                self::requireString($data, 'name'),
                self::resultContent(Json::array($data, 'content')),
                is_bool($data['isError'] ?? null) && $data['isError'],
            ),
            'thinking' => new Thinking(self::requireString($data, 'text'), self::requireString($data, 'provider'), self::stringKeys(Json::array($data, 'opaque'))),
            'provider' => new ProviderPart(self::requireString($data, 'provider'), self::requireString($data, 'json')),
            default => throw new InvalidArgumentException('Unknown stored part type "' . (string) $type . '".'),
        };
    }

    /**
     * @param array<mixed> $content
     * @return list<Text|Image|Document>
     * @throws InvalidArgumentException
     */
    private static function resultContent(array $content): array
    {
        $out = [];
        foreach ($content as $item) {
            $part = is_array($item) ? self::partFromArray($item) : null;
            if (!$part instanceof Text && !$part instanceof Image && !$part instanceof Document) {
                throw new InvalidArgumentException('A stored tool result holds only text, image or document parts.');
            }
            $out[] = $part;
        }

        return $out;
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidArgumentException
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('A stored part lacks the string field "' . $key . '".');
        }

        return $value;
    }

    /**
     * @param array<string|Part> $parts
     * @return list<Part>
     */
    private static function parts(array $parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            $out[] = is_string($part) ? new Text($part) : $part;
        }

        return $out;
    }
}
