<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Pipeline;

use Ldkafka\Ai\Exception\AiException;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Exception\ErrorKind;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Exception\TransportException;
use Ldkafka\Ai\Pipeline\RetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{AiException, bool}>
     */
    public static function errors(): iterable
    {
        yield '429 with retry-after' => [self::provider(ErrorKind::RateLimited, 429, 2.0), true];
        yield '429 without retry-after' => [self::provider(ErrorKind::RateLimited, 429), false];
        yield 'spend cap' => [self::provider(ErrorKind::Billing, 429), false];
        yield '529' => [self::provider(ErrorKind::Overloaded, 529), true];
        yield '500' => [self::provider(ErrorKind::Server, 500), true];
        yield '504' => [self::provider(ErrorKind::Timeout, 504), true];
        yield '400' => [self::provider(ErrorKind::InvalidRequest, 400), false];
        yield '401' => [self::provider(ErrorKind::Authentication, 401), false];
        yield '413' => [self::provider(ErrorKind::RequestTooLarge, 413), false];
        yield 'invalid response' => [self::provider(ErrorKind::InvalidResponse, 200), false];
        yield 'connection failure' => [new TransportException('refused'), true];
        yield 'read timeout' => [new TransportException('timed out', true), false];
        yield 'cancelled before send' => [new TransportException('cancelled', cancelled: true), false];
        yield 'configuration' => [new ConfigurationException('x'), false];
    }

    #[DataProvider('errors')]
    public function testWhatIsRetried(AiException $error, bool $retried): void
    {
        self::assertSame($retried, (new RetryPolicy())->delayMs(1, $error) !== null);
    }

    public function testRetryAfterIsWaitedWhenShortEnough(): void
    {
        $policy = new RetryPolicy(maxRetryAfterSeconds: 60);

        self::assertSame(1500, $policy->delayMs(1, self::provider(ErrorKind::RateLimited, 429, 1.5)));
        self::assertSame(60_000, $policy->delayMs(1, self::provider(ErrorKind::Overloaded, 529, 60.0)));
        self::assertNull($policy->delayMs(1, self::provider(ErrorKind::RateLimited, 429, 61.0)));
    }

    public function testBackoffWithJitterAndCap(): void
    {
        $policy = new RetryPolicy(maxRetries: 10, baseDelayMs: 500, maxDelayMs: 8000);
        $error = self::provider(ErrorKind::Server, 500);

        foreach ([1 => 500, 2 => 1000, 3 => 2000, 4 => 4000, 5 => 8000, 6 => 8000] as $retry => $full) {
            $delay = $policy->delayMs($retry, $error);
            self::assertNotNull($delay);
            self::assertGreaterThanOrEqual((int) ($full * 0.75), $delay);
            self::assertLessThanOrEqual($full, $delay);
        }
    }

    public function testRetriesAreBounded(): void
    {
        $policy = new RetryPolicy(maxRetries: 2);
        $error = self::provider(ErrorKind::Server, 500);

        self::assertNotNull($policy->delayMs(2, $error));
        self::assertNull($policy->delayMs(3, $error));
        self::assertNull($policy->delayMs(0, $error));
    }

    private static function provider(ErrorKind $kind, int $status, ?float $retryAfter = null): ProviderException
    {
        return new ProviderException('error', 'p', $kind, $status, null, null, $retryAfter);
    }

    public function testTransportFailureAfterTheRequestWasSentIsNotRetried(): void
    {
        $error = new TransportException('connection reset after send', false, true);

        self::assertTrue($error->isAfterSend());
        self::assertFalse($error->isTimeout());
        self::assertNull((new RetryPolicy())->delayMs(1, $error));
        self::assertTrue((new TransportException('timed out', true))->isAfterSend(), 'A read timeout is after send.');
    }
}
