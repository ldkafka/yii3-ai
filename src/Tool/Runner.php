<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tool;

use Closure;
use InvalidArgumentException;
use Ldkafka\Ai\Client;
use Ldkafka\Ai\Context\ContextPolicyInterface;
use Ldkafka\Ai\Context\FullReplay;
use Ldkafka\Ai\Exception\AiException;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Exception\ToolException;
use Ldkafka\Ai\Message\Document;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\Role;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;
use Ldkafka\Ai\StopReason;
use Ldkafka\Ai\Usage;
use Psr\Log\LoggerInterface;
use Throwable;

use function is_string;

/**
 * The bounded tool loop.
 *
 * Offers exactly the registry's tools on every step (a forced tool choice on the first step only,
 * auto after it), applies the context policy once, stops at
 * maxSteps or when the summed cost reaches maxCostUsd (a soft limit: one call may overshoot it),
 * answers every tool call the model made in one user message, and resends after a Paused stop.
 * Only ToolException text reaches the model; any other Throwable becomes "Tool X failed" and is
 * logged locally.
 */
final class Runner
{
    /**
     * @param Client $client Makes the model calls.
     * @param ToolRegistry $tools The tools of the run (the allow-list).
     * @param ContextPolicyInterface $context What history to send.
     * @param int $maxSteps Model calls per run, at least 1.
     * @param float|null $maxCostUsd Stop once the summed cost reaches this; needs a priced model.
     * @param LoggerInterface|null $logger Receives a warning per failing tool.
     * @throws InvalidArgumentException When maxSteps < 1 or maxCostUsd <= 0.
     */
    public function __construct(
        private Client $client,
        private ToolRegistry $tools = new ToolRegistry(),
        private ContextPolicyInterface $context = new FullReplay(),
        private int $maxSteps = 10,
        private ?float $maxCostUsd = null,
        private ?LoggerInterface $logger = null,
    ) {
        if ($maxSteps < 1) {
            throw new InvalidArgumentException('maxSteps must be at least 1.');
        }
        if ($maxCostUsd !== null && $maxCostUsd <= 0.0) {
            throw new InvalidArgumentException('maxCostUsd must be positive.');
        }
    }

    /**
     * A copy with another tool set.
     */
    public function withTools(ToolRegistry $tools): self
    {
        return new self($this->client, $tools, $this->context, $this->maxSteps, $this->maxCostUsd, $this->logger);
    }

    /**
     * Run the loop.
     *
     * @param (Closure(Message, ?Response): void)|null $onMessage Called for each appended message,
     *        with the Response for assistant messages.
     * @throws InvalidArgumentException When the request carries its own tools.
     * @throws ConfigurationException When maxCostUsd is set and the model has no price.
     * @throws AiException When a model call fails.
     */
    public function run(Request $request, ?Closure $onMessage = null): RunResult
    {
        if ($request->tools !== []) {
            throw new InvalidArgumentException('Pass tools to the Runner through its ToolRegistry, not in the Request.');
        }
        $model = $this->client->model($request->model);
        if ($this->maxCostUsd !== null && $model->price === null) {
            throw new ConfigurationException('maxCostUsd needs a price for model "' . $model->name . '".');
        }

        $request = $this->context->apply($request->withTools($this->tools->specs()), $model);

        $messages = [];
        $usage = new Usage();
        $cost = null;
        $steps = 0;
        $status = RunStatus::Completed;
        $response = null;

        while (true) {
            if ($steps >= $this->maxSteps) {
                $status = RunStatus::StepLimit;
                break;
            }
            if ($this->maxCostUsd !== null && $cost !== null && $cost->total() >= $this->maxCostUsd) {
                $status = RunStatus::BudgetExhausted;
                break;
            }

            $response = $this->client->complete($request);
            $steps++;
            // A forced choice (required or tool) holds for the first step only; forcing every
            // step would make the model call tools until the step limit.
            if ($steps === 1 && $request->toolChoice !== null && $request->toolChoice->isForced()) {
                $request = $request->withToolChoice(ToolChoice::auto($request->toolChoice->parallel));
            }
            $usage = $usage->add($response->usage);
            if ($response->cost !== null) {
                $cost = $cost === null ? $response->cost : $cost->add($response->cost);
            }
            $request = $request->withMessage($response->message);
            $messages[] = $response->message;
            if ($onMessage !== null) {
                $onMessage($response->message, $response);
            }

            if ($response->stopReason === StopReason::ToolUse && $response->toolCalls() !== []) {
                $results = [];
                foreach ($response->toolCalls() as $call) {
                    $results[] = $this->runTool($call);
                }
                $answer = new Message(Role::User, $results);
                $request = $request->withMessage($answer);
                $messages[] = $answer;
                if ($onMessage !== null) {
                    $onMessage($answer, null);
                }
                continue;
            }
            if ($response->stopReason === StopReason::Paused) {
                continue;
            }
            break;
        }

        // maxSteps >= 1 and the budget check needs a cost, so at least one call was made.
        if (!$response instanceof Response) {
            throw new InvalidArgumentException('The run made no model call.');
        }

        return new RunResult($response, $messages, $usage, $cost, $steps, $status);
    }

    /**
     * Run one tool call and turn the outcome into a result.
     */
    private function runTool(ToolCall $call): ToolResult
    {
        $tool = $this->tools->get($call->name);
        if ($tool === null) {
            return ToolResult::text($call->id, $call->name, 'Tool ' . $call->name . ' is not available.', true);
        }

        try {
            $output = $tool->handle($call->arguments());
        } catch (ToolException $e) {
            return ToolResult::text($call->id, $call->name, $e->getMessage(), true);
        } catch (Throwable $e) {
            $this->logger?->warning('Tool {tool} failed: {class}: {error}', [
                'tool' => $call->name,
                'class' => $e::class,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ToolResult::text($call->id, $call->name, 'Tool ' . $call->name . ' failed.', true);
        }

        if (is_string($output)) {
            return ToolResult::text($call->id, $call->name, $output);
        }
        foreach ($output as $part) {
            if (!$part instanceof Text && !$part instanceof Image && !$part instanceof Document) {
                $this->logger?->warning('Tool {tool} returned a list holding something other than Text, Image or Document.', [
                    'tool' => $call->name,
                ]);

                return ToolResult::text($call->id, $call->name, 'Tool ' . $call->name . ' failed.', true);
            }
        }

        return new ToolResult($call->id, $call->name, $output);
    }
}
