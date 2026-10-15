<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Provider\Anthropic;

use InvalidArgumentException;
use JsonException;
use Ldkafka\Ai\CacheHint;
use Ldkafka\Ai\CacheScope;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Exception\ErrorKind;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Http\HttpResponse;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Message\Document;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\Part;
use Ldkafka\Ai\Message\ProviderPart;
use Ldkafka\Ai\Message\Role;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Message\Thinking;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Model\Capability;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;
use Ldkafka\Ai\StopReason;
use Ldkafka\Ai\Tool\ToolChoice;
use Ldkafka\Ai\Tool\ToolChoiceMode;
use Ldkafka\Ai\Usage;
use stdClass;

use function array_is_list;
use function array_key_last;
use function count;
use function function_exists;
use function is_array;
use function is_string;
use function mb_substr;
use function preg_replace;
use function sprintf;
use function strip_tags;
use function strlen;
use function substr;
use function trim;

/**
 * Maps between the neutral types and the Anthropic Messages API. Pure: no I/O.
 *
 * @internal The unit the conformance fixtures test; not covered by semver.
 */
final class AnthropicMapper
{
    public const PROVIDER = 'anthropic';

    private const SNIPPET_CHARS = 300;

    /**
     * The request body.
     *
     * @return array<string, mixed>
     * @throws ConfigurationException On an unsupported cache TTL or duplicate cache hints.
     */
    public function toPayload(Request $request, ModelProfile $model): array
    {
        $hints = $model->supports(Capability::PromptCache) ? $this->cacheControls($request->cache) : [];

        $payload = ['model' => $model->id, 'max_tokens' => $request->maxTokens ?? $model->maxTokens];
        if ($request->system !== null && $request->system !== '') {
            $payload['system'] = isset($hints[CacheScope::System->value])
                ? [['type' => 'text', 'text' => $request->system, 'cache_control' => $hints[CacheScope::System->value]]]
                : $request->system;
        }
        $payload['messages'] = $this->messages($request->messages);

        if ($request->tools !== []) {
            $tools = [];
            foreach ($request->tools as $spec) {
                $tools[] = ['name' => $spec->name, 'description' => $spec->description, 'input_schema' => self::schema($spec->inputSchema)];
            }
            if (isset($hints[CacheScope::Tools->value])) {
                $tools[array_key_last($tools)]['cache_control'] = $hints[CacheScope::Tools->value];
            }
            $payload['tools'] = $tools;
        }
        if ($request->toolChoice !== null) {
            $payload['tool_choice'] = self::toolChoice($request->toolChoice);
        }
        if ($request->responseFormat !== null) {
            $payload['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $request->responseFormat->schema]];
        }
        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }
        if ($request->stop !== []) {
            $payload['stop_sequences'] = $request->stop;
        }
        if (isset($request->metadata['user_id'])) {
            $payload['metadata'] = ['user_id' => $request->metadata['user_id']];
        }
        if (isset($hints[CacheScope::Messages->value])) {
            $payload['cache_control'] = $hints[CacheScope::Messages->value];
        }

        $payload = self::merge($payload, $model->options);
        $options = $request->providerOptions[self::PROVIDER] ?? [];
        unset($options['betas']);

        return self::merge($payload, $options);
    }

    /**
     * The beta names a request adds through `providerOptions['anthropic']['betas']`.
     *
     * @return list<string>
     */
    public function betas(Request $request): array
    {
        $betas = [];
        $options = $request->providerOptions[self::PROVIDER] ?? [];
        foreach (is_array($options['betas'] ?? null) ? $options['betas'] : [] as $beta) {
            if (is_string($beta) && $beta !== '') {
                $betas[] = $beta;
            }
        }

        return $betas;
    }

    /**
     * Map a 2xx body to a Response. Cost and latency are left to the Client.
     *
     * @throws ProviderException With kind InvalidResponse when the body is not a message.
     */
    public function toResponse(string $json, ModelProfile $model, ?string $requestId = null): Response
    {
        try {
            $data = Json::decode($json);
            $objects = Json::decodeObjects($json);
        } catch (JsonException $e) {
            throw $this->invalid($requestId, $e);
        }
        if (!is_array($data) || !is_array($data['content'] ?? null) || !$objects instanceof stdClass
            || !is_array($objects->content ?? null)) {
            throw $this->invalid($requestId);
        }
        $blockObjects = $objects->content;

        $parts = [];
        try {
            foreach ($data['content'] as $index => $block) {
                if (!is_array($block)) {
                    throw $this->invalid($requestId);
                }
                $parts[] = $this->part($block, $blockObjects[$index] ?? new stdClass());
            }
        } catch (InvalidArgumentException $e) {
            throw $this->invalid($requestId, $e);
        }

        $raw = Json::string($data, 'stop_reason') ?? '';

        return new Response(
            new Message(Role::Assistant, $parts),
            self::stopReason($raw),
            $raw,
            self::usage(Json::array($data, 'usage')),
            self::PROVIDER,
            Json::string($data, 'model') ?? $model->id,
            Json::string($data, 'id'),
            $requestId,
            null,
            0,
            $data,
        );
    }

    /**
     * Map an error answer to an exception. The message never holds the key or the request.
     */
    public function toException(HttpResponse $response): ProviderException
    {
        $status = $response->status;
        $requestId = $response->header('request-id');
        $errorType = null;
        $message = null;
        $errorCode = null;
        try {
            $data = Json::decode($response->body);
        } catch (JsonException) {
            $data = null;
        }
        if (is_array($data) && is_array($data['error'] ?? null)) {
            $error = $data['error'];
            $errorType = Json::string($error, 'type');
            $message = Json::string($error, 'message');
            $errorCode = Json::string(Json::array($error, 'details'), 'error_code');
            $requestId ??= Json::string($data, 'request_id');
        }
        $message ??= self::snippet($response->body);
        $retryAfter = $response->retryAfter();

        $kind = match (true) {
            $status === 529 || $errorType === 'overloaded_error' => ErrorKind::Overloaded,
            $status === 401 => ErrorKind::Authentication,
            $status === 402 => ErrorKind::Billing,
            $status === 403 => ErrorKind::Permission,
            $status === 404 => ErrorKind::NotFound,
            $status === 408, $status === 504 => ErrorKind::Timeout,
            $status === 413 => ErrorKind::RequestTooLarge,
            $status === 429 => $retryAfter === null && $errorCode === 'enforced_spend_limit_reached'
                ? ErrorKind::Billing
                : ErrorKind::RateLimited,
            $status >= 500 => ErrorKind::Server,
            $status >= 400 => ErrorKind::InvalidRequest,
            default => ErrorKind::Unknown,
        };

        return new ProviderException(
            sprintf(
                'Anthropic API error (HTTP %d%s): %s%s',
                $status,
                $errorType !== null ? ' ' . $errorType : '',
                $message,
                $requestId !== null ? ' [request ' . $requestId . ']' : '',
            ),
            self::PROVIDER,
            $kind,
            $status,
            $errorType,
            $requestId,
            $retryAfter,
        );
    }

    /**
     * @param list<Message> $messages
     * @return list<array{role: string, content: list<array<string, mixed>|stdClass>}>
     */
    private function messages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $results = [];
            $others = [];
            foreach ($message->parts as $part) {
                $block = $this->block($part);
                if ($block === null) {
                    continue;
                }
                if ($part instanceof ToolResult) {
                    $results[] = $block;
                } else {
                    $others[] = $block;
                }
            }
            // The API wants tool results first in the user message.
            $content = [...$results, ...$others];
            if ($content !== []) {
                $out[] = ['role' => $message->role->value, 'content' => $content];
            }
        }

        return $out;
    }

    /**
     * One part as an API block; null when it is not sent.
     *
     * @return array<string, mixed>|stdClass|null
     */
    private function block(Part $part): array|stdClass|null
    {
        if ($part instanceof Text) {
            return $part->text === '' ? null : ['type' => 'text', 'text' => $part->text];
        }
        if ($part instanceof Image) {
            return ['type' => 'image', 'source' => $part->data !== null
                ? ['type' => 'base64', 'media_type' => $part->mediaType, 'data' => $part->data]
                : ['type' => 'url', 'url' => (string) $part->url]];
        }
        if ($part instanceof Document) {
            $block = ['type' => 'document', 'source' => $part->data !== null
                ? ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $part->data]
                : ['type' => 'url', 'url' => (string) $part->url]];
            if ($part->title !== null) {
                $block['title'] = $part->title;
            }

            return $block;
        }
        if ($part instanceof ToolCall) {
            return ['type' => 'tool_use', 'id' => $part->id, 'name' => $part->name, 'input' => Json::decodeObjects($part->argumentsJson)];
        }
        if ($part instanceof ToolResult) {
            $block = ['type' => 'tool_result', 'tool_use_id' => $part->callId];
            if (count($part->content) === 1 && $part->content[0] instanceof Text) {
                $block['content'] = $part->content[0]->text;
            } else {
                $content = [];
                foreach ($part->content as $item) {
                    $mapped = $this->block($item);
                    if ($mapped !== null) {
                        $content[] = $mapped;
                    }
                }
                if ($content !== []) {
                    $block['content'] = $content;
                }
            }
            if ($part->isError) {
                $block['is_error'] = true;
            }

            return $block;
        }
        if ($part instanceof Thinking) {
            if ($part->provider !== self::PROVIDER) {
                return null;
            }
            if (isset($part->opaque['data'])) {
                return ['type' => 'redacted_thinking', 'data' => $part->opaque['data']];
            }

            return ['type' => 'thinking', 'thinking' => $part->text, 'signature' => $part->opaque['signature'] ?? ''];
        }
        if ($part instanceof ProviderPart && $part->provider === self::PROVIDER) {
            $block = Json::decodeObjects($part->json);

            return $block instanceof stdClass ? $block : null;
        }

        return null;
    }

    /**
     * One response block as a part.
     *
     * @param array<mixed> $block
     * @throws InvalidArgumentException When a known block is malformed.
     */
    private function part(array $block, mixed $object): Part
    {
        return match (Json::string($block, 'type')) {
            'text' => new Text(Json::string($block, 'text') ?? ''),
            'thinking' => new Thinking(
                Json::string($block, 'thinking') ?? '',
                self::PROVIDER,
                ['signature' => Json::string($block, 'signature') ?? ''],
            ),
            'redacted_thinking' => new Thinking('', self::PROVIDER, ['data' => Json::string($block, 'data') ?? '']),
            'tool_use' => new ToolCall(
                Json::string($block, 'id') ?? '',
                Json::string($block, 'name') ?? '',
                Json::encode($object instanceof stdClass ? ($object->input ?? new stdClass()) : new stdClass()),
            ),
            default => new ProviderPart(self::PROVIDER, Json::encode($object)),
        };
    }

    /**
     * @param array<mixed> $usage
     */
    private static function usage(array $usage): Usage
    {
        $details = [];
        $creation = Json::array($usage, 'cache_creation');
        if (isset($creation['ephemeral_1h_input_tokens'])) {
            $details['cacheWrite1h'] = Json::int($creation, 'ephemeral_1h_input_tokens');
        }

        return new Usage(
            Json::int($usage, 'input_tokens'),
            Json::int($usage, 'output_tokens'),
            Json::int($usage, 'cache_read_input_tokens'),
            Json::int($usage, 'cache_creation_input_tokens'),
            Json::int(Json::array($usage, 'output_tokens_details'), 'thinking_tokens'),
            $details,
        );
    }

    /**
     * The neutral stop reason for an Anthropic value.
     */
    private static function stopReason(string $raw): StopReason
    {
        return match ($raw) {
            'end_turn' => StopReason::Complete,
            'max_tokens' => StopReason::MaxTokens,
            'stop_sequence' => StopReason::StopSequence,
            'tool_use' => StopReason::ToolUse,
            'pause_turn' => StopReason::Paused,
            'refusal' => StopReason::Refused,
            'model_context_window_exceeded' => StopReason::ContextLimit,
            default => StopReason::Other,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function toolChoice(ToolChoice $choice): array
    {
        $out = match ($choice->mode) {
            ToolChoiceMode::None => ['type' => 'none'],
            ToolChoiceMode::Required => ['type' => 'any'],
            ToolChoiceMode::Tool => ['type' => 'tool', 'name' => (string) $choice->name],
            default => ['type' => 'auto'],
        };
        if (!$choice->parallel && $choice->mode !== ToolChoiceMode::None) {
            $out['disable_parallel_tool_use'] = true;
        }

        return $out;
    }

    /**
     * Cache-control objects by scope.
     *
     * @param list<CacheHint> $hints
     * @return array<string, array{type: string, ttl?: string}>
     * @throws ConfigurationException
     */
    private function cacheControls(array $hints): array
    {
        $out = [];
        foreach ($hints as $hint) {
            if (isset($out[$hint->scope->value])) {
                throw new ConfigurationException('Only one cache hint per scope: "' . $hint->scope->value . '" is repeated.');
            }
            $out[$hint->scope->value] = match ($hint->ttlSeconds) {
                null, 300 => ['type' => 'ephemeral'],
                3600 => ['type' => 'ephemeral', 'ttl' => '1h'],
                default => throw new ConfigurationException('Anthropic caches for 300 or 3600 seconds only.'),
            };
        }

        // The API processes tools, then system, then messages, and wants every 1 hour entry
        // before any 5 minute one.
        $fiveMinutes = null;
        foreach ([CacheScope::Tools, CacheScope::System, CacheScope::Messages] as $scope) {
            $control = $out[$scope->value] ?? null;
            if ($control === null) {
                continue;
            }
            if (isset($control['ttl'])) {
                if ($fiveMinutes !== null) {
                    throw new ConfigurationException(sprintf(
                        'A 1 hour cache hint (%s) cannot follow a 5 minute one (%s); the order is tools, system, messages.',
                        $scope->value,
                        $fiveMinutes,
                    ));
                }
            } else {
                $fiveMinutes ??= $scope->value;
            }
        }

        return $out;
    }

    /**
     * A tool input schema; an object schema always carries `properties` as a JSON object.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function schema(array $schema): array
    {
        if ($schema === []) {
            $schema = ['type' => 'object'];
        }
        if (($schema['type'] ?? null) === 'object' && (($schema['properties'] ?? []) === [])) {
            $schema['properties'] = new stdClass();
        }

        return $schema;
    }

    /**
     * Merge $over into $base: maps merge recursively, everything else (lists included) is replaced.
     *
     * @param array<mixed> $base
     * @param array<mixed> $over
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $current = $base[$key] ?? null;
            if (is_array($value) && is_array($current) && !array_is_list($value) && !array_is_list($current)) {
                $base[$key] = self::merge($current, $value);
            } else {
                $base[$key] = $value;
            }
        }
        $out = [];
        foreach ($base as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * The first characters of a non-JSON body, without markup.
     */
    private static function snippet(string $body): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));
        if ($text === '') {
            return 'empty body';
        }

        return strlen($text) > self::SNIPPET_CHARS ? self::cut($text) : $text;
    }

    /**
     * The first characters of a text, UTF-8 safe when mbstring is present.
     */
    private static function cut(string $text): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, self::SNIPPET_CHARS) : substr($text, 0, self::SNIPPET_CHARS);
    }

    /**
     * The exception for a 2xx body that is not a message.
     */
    private function invalid(?string $requestId, ?\Throwable $previous = null): ProviderException
    {
        return new ProviderException(
            'Anthropic API returned a body that is not a message' . ($requestId !== null ? ' [request ' . $requestId . ']' : ''),
            self::PROVIDER,
            ErrorKind::InvalidResponse,
            200,
            null,
            $requestId,
            null,
            $previous,
        );
    }
}
