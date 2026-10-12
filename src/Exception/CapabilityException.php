<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Exception;

use Ldkafka\Ai\Model\Capability;

use function array_map;
use function array_values;
use function implode;
use function sprintf;

/**
 * The request needs capabilities the model lacks. Thrown before any HTTP call.
 */
final class CapabilityException extends AiException
{
    /** @var list<Capability> */
    public readonly array $missing;

    /**
     * @param string $model The catalog name.
     * @param array<Capability> $missing What the model lacks.
     */
    public function __construct(string $model, array $missing)
    {
        $this->missing = array_values($missing);
        parent::__construct(sprintf(
            'Model "%s" lacks what the request needs: %s.',
            $model,
            implode(', ', array_map(static fn (Capability $c): string => $c->value, $this->missing)),
        ));
    }
}
