<?php

declare(strict_types=1);

namespace Ldkafka\Ai;

use InvalidArgumentException;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Model\Capability;
use Ldkafka\Ai\Tool\ToolChoice;
use Ldkafka\Ai\Tool\ToolSpec;

use function array_values;

/**
 * Everything one model call needs. Immutable: the with*() methods return a copy.
 */
final readonly class Request
{
    /** @var list<Message> */
    public array $messages;

    /** @var list<ToolSpec> */
    public array $tools;

    /** @var list<string> */
    public array $stop;

    /** @var list<CacheHint> */
    public array $cache;

    /** @var list<Capability> */
    public array $requires;

    /**
     * @param array<Message> $messages The conversation, oldest first.
     * @param string $model A catalog name; '' = the catalog default.
     * @param string|null $system The system prompt.
     * @param array<ToolSpec> $tools Tools offered to the model.
     * @param ToolChoice|null $toolChoice Whether and which tool the model must call.
     * @param ResponseFormat|null $responseFormat A JSON Schema the answer must match.
     * @param int|null $maxTokens Output limit; null = the model's configured value.
     * @param float|null $temperature Sampling temperature; null = the provider default.
     * @param array<string> $stop Stop sequences.
     * @param array<string, string> $metadata Advisory metadata; providers send the keys they accept.
     * @param array<CacheHint> $cache Prompt-cache breakpoints.
     * @param array<Capability> $requires Capabilities the model must have.
     * @param array<string, array<string, mixed>> $providerOptions Per provider, merged into its payload last.
     * @throws InvalidArgumentException When the temperature or an option is NaN or infinite.
     */
    public function __construct(
        array $messages = [],
        public string $model = '',
        public ?string $system = null,
        array $tools = [],
        public ?ToolChoice $toolChoice = null,
        public ?ResponseFormat $responseFormat = null,
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        array $stop = [],
        public array $metadata = [],
        array $cache = [],
        array $requires = [],
        public array $providerOptions = [],
    ) {
        Json::assertFinite($temperature, 'The temperature');
        Json::assertFinite($providerOptions, 'providerOptions');
        $this->messages = array_values($messages);
        $this->tools = array_values($tools);
        $this->stop = array_values($stop);
        $this->cache = array_values($cache);
        $this->requires = array_values($requires);
    }

    /**
     * A copy with one message appended.
     */
    public function withMessage(Message $message): self
    {
        return $this->copy([...$this->messages, $message], $this->model, $this->tools);
    }

    /**
     * A copy with the messages replaced.
     *
     * @param array<Message> $messages
     */
    public function withMessages(array $messages): self
    {
        return $this->copy($messages, $this->model, $this->tools);
    }

    /**
     * A copy for another catalog model.
     */
    public function withModel(string $model): self
    {
        return $this->copy($this->messages, $model, $this->tools);
    }

    /**
     * A copy with the tools replaced.
     *
     * @param array<ToolSpec> $tools
     */
    public function withTools(array $tools): self
    {
        return $this->copy($this->messages, $this->model, $tools);
    }

    /**
     * A copy with another tool choice.
     */
    public function withToolChoice(?ToolChoice $toolChoice): self
    {
        return $this->copy($this->messages, $this->model, $this->tools, $toolChoice, false);
    }

    /**
     * @param array<Message> $messages
     * @param array<ToolSpec> $tools
     */
    private function copy(array $messages, string $model, array $tools, ?ToolChoice $toolChoice = null, bool $keepChoice = true): self
    {
        return new self(
            $messages,
            $model,
            $this->system,
            $tools,
            $keepChoice ? $this->toolChoice : $toolChoice,
            $this->responseFormat,
            $this->maxTokens,
            $this->temperature,
            $this->stop,
            $this->metadata,
            $this->cache,
            $this->requires,
            $this->providerOptions,
        );
    }
}
