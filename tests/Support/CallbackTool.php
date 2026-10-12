<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Support;

use Closure;
use Ldkafka\Ai\Message\Document;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Tool\ToolInterface;

/**
 * A tool whose handle() runs a closure; records the arguments of each call.
 */
final class CallbackTool implements ToolInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /**
     * @param string $name The tool name.
     * @param Closure(array<string, mixed>): (string|list<Text|Image|Document>) $handler Runs the call.
     * @param array<string, mixed> $schema The input schema.
     */
    public function __construct(
        private string $name,
        private Closure $handler,
        private array $schema = ['type' => 'object', 'properties' => []],
    ) {
    }

    /**
     * The tool name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * A fixed description.
     */
    public function getDescription(): string
    {
        return 'Test tool ' . $this->name . '.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return $this->schema;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string|list<Text|Image|Document>
     */
    public function handle(array $arguments): string|array
    {
        $this->calls[] = $arguments;

        return ($this->handler)($arguments);
    }
}
