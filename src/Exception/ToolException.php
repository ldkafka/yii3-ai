<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Exception;

/**
 * Thrown by a tool. Its message is shown to the model, so it must hold nothing secret.
 */
final class ToolException extends AiException
{
}
