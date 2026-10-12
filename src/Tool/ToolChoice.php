<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tool;

/**
 * Whether and which tool the model must call.
 *
 * In a Runner, a forced choice (required or tool) applies to the first step only; later steps use
 * auto with the same parallel setting, so the run can end.
 */
final readonly class ToolChoice
{
    /**
     * @param ToolChoiceMode $mode Whether the model may, must or must not call a tool.
     * @param string|null $name The tool name for ToolChoiceMode::Tool.
     * @param bool $parallel Whether the model may call several tools at once.
     */
    private function __construct(public ToolChoiceMode $mode, public ?string $name = null, public bool $parallel = true)
    {
    }

    /**
     * The model decides.
     */
    public static function auto(bool $parallel = true): self
    {
        return new self(ToolChoiceMode::Auto, null, $parallel);
    }

    /**
     * The model must not call a tool.
     */
    public static function none(): self
    {
        return new self(ToolChoiceMode::None);
    }

    /**
     * The model must call at least one tool.
     */
    public static function required(bool $parallel = true): self
    {
        return new self(ToolChoiceMode::Required, null, $parallel);
    }

    /**
     * The model must call this tool.
     */
    public static function tool(string $name): self
    {
        return new self(ToolChoiceMode::Tool, $name);
    }

    /**
     * Whether the choice forces a tool call (required or tool).
     */
    public function isForced(): bool
    {
        return $this->mode === ToolChoiceMode::Required || $this->mode === ToolChoiceMode::Tool;
    }
}
