<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Pipeline;

use Ldkafka\Ai\Exception\AiException;
use Ldkafka\Ai\Exception\ErrorKind;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Pipeline\HandlerInterface;
use Ldkafka\Ai\Pipeline\RetryMiddleware;
use Ldkafka\Ai\Pipeline\RetryPolicy;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;
use Ldkafka\Ai\Tests\Support\ArrayLogger;
use Ldkafka\Ai\Tests\Support\FakeProvider;
use PHPUnit\Framework\TestCase;

final class RetryMiddlewareTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    public function testRetriesUntilSuccessAndLogsEachRetry(): void
    {
        $logger = new ArrayLogger();
        $next = $this->handler([
            new ProviderException('busy', 'p', ErrorKind::Overloaded, 529, 'overloaded_error', 'req_a', 2.0),
            new ProviderException('down', 'p', ErrorKind::Server, 500),
            FakeProvider::response(Message::assistant('ok')),
        ]);

        $response = $this->middleware($logger)->process(new Request(), $this->model(), $next);

        self::assertSame('ok', $response->text());
        self::assertSame(2000, $this->sleeps[0]);
        self::assertCount(2, $this->sleeps);
        self::assertCount(2, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('retry 1 in 2000 ms: overloaded HTTP 529 [request req_a]', $logger->records[0]['message']);
    }

    public function testNonRetryableErrorIsRethrownAtOnce(): void
    {
        $error = new ProviderException('bad', 'p', ErrorKind::InvalidRequest, 400);
        $next = $this->handler([$error]);

        try {
            $this->middleware()->process(new Request(), $this->model(), $next);
            self::fail('Expected the error');
        } catch (ProviderException $e) {
            self::assertSame($error, $e);
        }
        self::assertSame([], $this->sleeps);
    }

    public function testExhaustedRetriesRethrowTheLastError(): void
    {
        $last = new ProviderException('down 3', 'p', ErrorKind::Server, 500);
        $next = $this->handler([
            new ProviderException('down 1', 'p', ErrorKind::Server, 500),
            new ProviderException('down 2', 'p', ErrorKind::Server, 500),
            $last,
        ]);

        try {
            $this->middleware()->process(new Request(), $this->model(), $next);
            self::fail('Expected the error');
        } catch (ProviderException $e) {
            self::assertSame($last, $e);
        }
        self::assertCount(2, $this->sleeps);
    }

    private function middleware(?ArrayLogger $logger = null): RetryMiddleware
    {
        return new RetryMiddleware(new RetryPolicy(), $logger, function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    /**
     * @param list<Response|AiException> $answers
     */
    private function handler(array $answers): HandlerInterface
    {
        $provider = new FakeProvider($answers);

        return new class ($provider) implements HandlerInterface {
            public function __construct(private FakeProvider $provider)
            {
            }

            public function handle(Request $request, ModelProfile $model): Response
            {
                return $this->provider->send($request, $model);
            }
        };
    }

    private function model(): ModelProfile
    {
        return new ModelProfile('m', 'p', 'm', [], 100, 10, 10);
    }
}
