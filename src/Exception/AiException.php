<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Exception;

use RuntimeException;

/**
 * Base of every exception this package throws on purpose. Messages never contain keys or prompts.
 */
class AiException extends RuntimeException
{
}
