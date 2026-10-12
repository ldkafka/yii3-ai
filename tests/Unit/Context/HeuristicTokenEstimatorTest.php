<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Context;

use Ldkafka\Ai\Context\HeuristicTokenEstimator;
use Ldkafka\Ai\Message\Document;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\ProviderPart;
use Ldkafka\Ai\Message\Thinking;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use PHPUnit\Framework\TestCase;

use function base64_encode;

final class HeuristicTokenEstimatorTest extends TestCase
{
    public function testTextCountsUtf8CharactersNotBytes(): void
    {
        $estimator = new HeuristicTokenEstimator();

        self::assertSame(2, $estimator->text('abcde'));
        self::assertSame(2, $estimator->text('ééééé'));
        self::assertSame(0, $estimator->text(''));
        self::assertSame(1, $estimator->text('a'));
    }

    public function testMediaAndStructuredParts(): void
    {
        $estimator = new HeuristicTokenEstimator(charsPerToken: 1.0, imageTokens: 100, pdfPageTokens: 1000);
        $twoPages = base64_encode('%PDF-1.4 <</Type /Pages /Kids [3 0 R 4 0 R]>> <</Type /Page>> <</Type/Page>>');

        self::assertSame(100, $estimator->message(Message::user(new Image('image/png', 'AAAA'))));
        self::assertSame(2000, $estimator->message(Message::user(new Document($twoPages))));
        self::assertSame(1000, $estimator->message(Message::user(Document::fromUrl('https://example.com/a.pdf'))));
        self::assertSame(1000, $estimator->message(Message::user(new Document('!!not base64!!'))));
        self::assertSame(9, $estimator->message(Message::assistant(new ToolCall('id', 'ab', '{"a":1}'))));
        self::assertSame(105, $estimator->message(Message::user(new ToolResult('id', 'n', [new \Ldkafka\Ai\Message\Text('hello'), new Image('image/png', 'AAAA')]))));
        self::assertSame(3, $estimator->message(Message::assistant(new Thinking('abc', 'anthropic'))));
        self::assertSame(2, $estimator->message(Message::assistant(new ProviderPart('anthropic', '{}'))));
    }
}
