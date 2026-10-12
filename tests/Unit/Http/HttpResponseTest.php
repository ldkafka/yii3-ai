<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Http;

use Ldkafka\Ai\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

final class HttpResponseTest extends TestCase
{
    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $response = new HttpResponse(200, ['Request-Id' => 'req_1'], 'body');

        self::assertSame(['request-id' => 'req_1'], $response->headers);
        self::assertSame('req_1', $response->header('REQUEST-ID'));
        self::assertNull($response->header('retry-after'));
    }
}
