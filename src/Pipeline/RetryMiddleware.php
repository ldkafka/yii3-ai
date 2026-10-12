<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Pipeline;

use Closure;
use Ldkafka\Ai\Exception\AiException;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;
use Psr\Log\LoggerInterface;

use function usleep;

/**
 * Retries what the policy allows and logs a warning per retry.
 */
final class RetryMiddleware implements MiddlewareInterface
{
    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param RetryPolicy $policy When to retry.
     * @param LoggerInterface|null $logger Receives one warning per retry.
     * @param (Closure(int): void)|null $sleep @internal Test hook taking milliseconds; default usleep.
     */
    public function __construct(
        private RetryPolicy $policy = new RetryPolicy(),
        private ?LoggerInterface $logger = null,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * Call $next, retrying failures the policy allows.
     *
     * @throws AiException The last failure.
     */
    public function process(Request $request, ModelProfile $model, HandlerInterface $next): Response
    {
        $retry = 0;
        while (true) {
            try {
                return $next->handle($request, $model);
            } catch (AiException $e) {
                $retry++;
                $delay = $this->policy->delayMs($retry, $e);
                if ($delay === null) {
                    throw $e;
                }
                $this->logger?->warning('AI call failed, retry {retry} in {wait} ms: {kind} HTTP {status} [request {requestId}]', [
                    'retry' => $retry,
                    'wait' => $delay,
                    'kind' => $e instanceof ProviderException ? $e->kind->value : 'transport',
                    'status' => $e instanceof ProviderException ? $e->httpStatus : 0,
                    'requestId' => $e instanceof ProviderException ? (string) $e->requestId : '',
                    'provider' => $model->provider,
                    'model' => $model->name,
                ]);
                ($this->sleep)($delay);
            }
        }
    }
}
