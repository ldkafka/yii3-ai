<?php

declare(strict_types=1);

namespace Ldkafka\Ai;

/**
 * "Cache the prompt up to here." Advisory: models without the promptCache capability ignore it
 * unless the request requires that capability.
 */
final readonly class CacheHint
{
    /**
     * @param CacheScope $scope Where the breakpoint goes.
     * @param int|null $ttlSeconds Null or 300 for the provider's default; 3600 for one hour.
     */
    public function __construct(public CacheScope $scope, public ?int $ttlSeconds = null)
    {
    }
}
