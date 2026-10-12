<?php

declare(strict_types=1);

namespace Ldkafka\Ai;

use Ldkafka\Ai\Exception\AiException;
use Ldkafka\Ai\Exception\CapabilityException;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Message\Document;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Model\Capability;
use Ldkafka\Ai\Model\CostCalculator;
use Ldkafka\Ai\Model\ModelCatalog;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Pipeline\HandlerInterface;
use Ldkafka\Ai\Pipeline\MiddlewareInterface;
use Ldkafka\Ai\Provider\ProviderRegistry;
use Psr\Log\LoggerInterface;

use function array_reverse;
use function hrtime;
use function in_array;
use function intdiv;
use function function_exists;
use function mb_substr;
use function sprintf;
use function substr;

/**
 * One model call: resolve the model, check capabilities, run middleware and provider, add cost and
 * latency, log one line.
 */
final class Client
{
    public const VERSION = '0.1.1';

    /**
     * @param ModelCatalog $models The configured models.
     * @param ProviderRegistry $providers The providers by name.
     * @param list<MiddlewareInterface> $middleware Outermost first.
     * @param CostCalculator $costs Prices usage.
     * @param LoggerInterface|null $logger Receives one info line per call and a warning per failure.
     */
    public function __construct(
        private ModelCatalog $models,
        private ProviderRegistry $providers,
        private array $middleware = [],
        private CostCalculator $costs = new CostCalculator(),
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Call the model.
     *
     * @throws ConfigurationException When the model or provider is unknown or misconfigured.
     * @throws CapabilityException Before any HTTP call, when the model lacks what the request needs.
     * @throws AiException When the provider or the transport fails (after the retries middleware allows).
     */
    public function complete(Request $request): Response
    {
        $model = $this->models->get($request->model);
        $missing = $this->missing($request, $model);
        if ($missing !== []) {
            throw new CapabilityException($model->name, $missing);
        }

        $attempts = 0;
        $count = static function () use (&$attempts): void {
            $attempts++;
        };
        $handler = new class ($this->providers, $count) implements HandlerInterface {
            /**
             * The last step: counts the attempt and calls the provider.
             */
            public function __construct(private ProviderRegistry $providers, private \Closure $count)
            {
            }

            /**
             * Count one attempt and send through the provider registry.
             */
            public function handle(Request $request, ModelProfile $model): Response
            {
                ($this->count)();

                return $this->providers->handle($request, $model);
            }
        };
        foreach (array_reverse($this->middleware) as $middleware) {
            $handler = new class ($middleware, $handler) implements HandlerInterface {
                /**
                 * One middleware around the next handler.
                 */
                public function __construct(private MiddlewareInterface $middleware, private HandlerInterface $next)
                {
                }

                /**
                 * Run the middleware with the next handler.
                 */
                public function handle(Request $request, ModelProfile $model): Response
                {
                    return $this->middleware->process($request, $model, $this->next);
                }
            };
        }

        $started = hrtime(true);
        try {
            $response = $handler->handle($request, $model);
        } catch (AiException $e) {
            $this->logger?->warning('AI call failed: {provider} {model} {kind} HTTP {status} {errorType} [request {requestId}] after {latencyMs} ms, {attempts} attempt(s): {error}', [
                'provider' => $model->provider,
                'model' => $model->name,
                'kind' => $e instanceof ProviderException ? $e->kind->value : $e::class,
                'status' => $e instanceof ProviderException ? $e->httpStatus : 0,
                'errorType' => $e instanceof ProviderException ? (string) $e->errorType : '',
                'requestId' => $e instanceof ProviderException ? (string) $e->requestId : '',
                'latencyMs' => self::elapsedMs($started),
                'attempts' => $attempts,
                'error' => self::cut($e->getMessage()),
            ]);
            throw $e;
        }
        $latencyMs = self::elapsedMs($started);
        $cost = $model->price !== null ? $this->costs->cost($response->usage, $model->price) : null;

        $response = new Response(
            $response->message,
            $response->stopReason,
            $response->rawStopReason,
            $response->usage,
            $response->provider,
            $response->model,
            $response->id,
            $response->requestId,
            $cost,
            $latencyMs,
            $response->raw,
        );

        $usage = $response->usage;
        $this->logger?->info('AI call {provider} {model}: {stopReason}, in {inputTokens} out {outputTokens} cache read {cacheReadTokens} write {cacheWriteTokens}, ${cost}, {latencyMs} ms, {attempts} attempt(s) [request {requestId}]', [
            'provider' => $response->provider,
            'model' => $response->model,
            'stopReason' => $response->rawStopReason,
            'inputTokens' => $usage->inputTokens,
            'outputTokens' => $usage->outputTokens,
            'cacheReadTokens' => $usage->cacheReadTokens,
            'cacheWriteTokens' => $usage->cacheWriteTokens,
            'reasoningTokens' => $usage->reasoningTokens,
            'cost' => $cost !== null ? sprintf('%.6f', $cost->total()) : 'n/a',
            'latencyMs' => $latencyMs,
            'attempts' => $attempts,
            'requestId' => (string) $response->requestId,
        ]);

        return $response;
    }

    /**
     * The profile for a catalog name; '' = the default.
     *
     * @throws ConfigurationException When the name is not in the catalog.
     */
    public function model(string $name = ''): ModelProfile
    {
        return $this->models->get($name);
    }

    /**
     * What the request needs and the model lacks.
     *
     * @return list<Capability>
     */
    private function missing(Request $request, ModelProfile $model): array
    {
        $needs = $request->requires;
        if ($request->tools !== [] || $request->toolChoice !== null) {
            $needs[] = Capability::Tools;
        }
        if ($request->responseFormat !== null) {
            $needs[] = Capability::JsonSchema;
        }
        foreach ($request->messages as $message) {
            foreach ($message->parts as $part) {
                $content = [$part];
                if ($part instanceof ToolCall) {
                    $needs[] = Capability::Tools;
                } elseif ($part instanceof ToolResult) {
                    $needs[] = Capability::Tools;
                    $content = $part->content;
                }
                foreach ($content as $item) {
                    if ($item instanceof Image) {
                        $needs[] = Capability::Vision;
                    } elseif ($item instanceof Document) {
                        $needs[] = Capability::Pdf;
                    }
                }
            }
        }

        $missing = [];
        foreach ($needs as $capability) {
            if (!$model->supports($capability) && !in_array($capability, $missing, true)) {
                $missing[] = $capability;
            }
        }

        return $missing;
    }

    /**
     * Whole milliseconds since an hrtime() mark.
     */
    private static function elapsedMs(int|float $started): int
    {
        return intdiv((int) (hrtime(true) - $started), 1_000_000);
    }

    /**
     * The first 300 characters of a text.
     */
    private static function cut(string $text): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, 300) : substr($text, 0, 300);
    }
}
