<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Exception;

use Ldkafka\Ai\Exception\AiException;
use Ldkafka\Ai\Exception\CapabilityException;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Exception\ContextOverflowException;
use Ldkafka\Ai\Exception\ErrorKind;
use Ldkafka\Ai\Exception\McpException;
use Ldkafka\Ai\Exception\ProviderException;
use Ldkafka\Ai\Exception\ToolException;
use Ldkafka\Ai\Exception\TransportException;
use Ldkafka\Ai\Model\Capability;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionsTest extends TestCase
{
    public function testEveryExceptionIsAnAiException(): void
    {
        foreach ([
            new ConfigurationException('x'),
            new ContextOverflowException('x'),
            new ToolException('x'),
            new TransportException('x'),
            new McpException('x'),
            new CapabilityException('m', []),
            new ProviderException('x', 'p', ErrorKind::Unknown, 0),
        ] as $exception) {
            self::assertInstanceOf(AiException::class, $exception);
            self::assertInstanceOf(RuntimeException::class, $exception);
        }
    }

    public function testCapabilityExceptionListsWhatIsMissing(): void
    {
        $e = new CapabilityException('claude-x', [Capability::Vision, Capability::Pdf]);

        self::assertSame('Model "claude-x" lacks what the request needs: vision, pdf.', $e->getMessage());
        self::assertSame([Capability::Vision, Capability::Pdf], $e->missing);
    }

    public function testProviderAndMcpFields(): void
    {
        $previous = new RuntimeException('cause');
        $provider = new ProviderException('m', 'anthropic', ErrorKind::RateLimited, 429, 'rate_limit_error', 'req_1', 3.5, $previous);
        $mcp = new McpException('m', 404, -32001, $previous);

        self::assertSame([429, 'rate_limit_error', 'req_1', 3.5], [$provider->httpStatus, $provider->errorType, $provider->requestId, $provider->retryAfter]);
        self::assertSame($previous, $provider->getPrevious());
        self::assertSame([404, -32001], [$mcp->httpStatus, $mcp->rpcCode]);
        self::assertFalse((new TransportException('x'))->isTimeout());
        self::assertTrue((new TransportException('x', true))->isTimeout());
    }
}
