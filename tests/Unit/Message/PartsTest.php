<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Tests\Unit\Message;

use InvalidArgumentException;
use Ldkafka\Ai\Message\Document;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\ProviderPart;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Message\Thinking;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Text, Image, Document, ToolCall, ToolResult, Thinking and ProviderPart.
 */
final class PartsTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testTextToArray(): void
    {
        self::assertSame(['type' => 'text', 'text' => 'hi'], (new Text('hi'))->toArray());
    }

    public function testImageNeedsExactlyOneSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Image('image/png', 'AAAA', 'https://example.com/a.png');
    }

    public function testImageNeedsASource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Image('image/png');
    }

    public function testImageRejectsUnsupportedMediaType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Image('image/svg+xml', 'AAAA');
    }

    public function testImageFromUrlAllowsEmptyMediaType(): void
    {
        $image = Image::fromUrl('https://example.com/a.png');

        self::assertSame(['type' => 'image', 'mediaType' => '', 'url' => 'https://example.com/a.png'], $image->toArray());
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function signatures(): iterable
    {
        yield 'png' => [(string) base64_decode(self::PNG), 'image/png'];
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0rest", 'image/jpeg'];
        yield 'gif' => ['GIF89a....', 'image/gif'];
        yield 'webp' => ['RIFF0000WEBPVP8 ', 'image/webp'];
        yield 'other' => ['<svg/>', null];
    }

    #[DataProvider('signatures')]
    public function testDetect(string $bytes, ?string $expected): void
    {
        self::assertSame($expected, Image::detect($bytes));
    }

    public function testImageFromFile(): void
    {
        $image = Image::fromFile($this->file((string) base64_decode(self::PNG)));

        self::assertSame('image/png', $image->mediaType);
        self::assertSame(self::PNG, $image->data);
    }

    public function testImageFromFileRejectsOtherFiles(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Image::fromFile($this->file('plain text'));
    }

    public function testImageFromMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Image::fromFile(sys_get_temp_dir() . '/does-not-exist-' . __LINE__ . '.png');
    }

    public function testDocumentFromFile(): void
    {
        $document = Document::fromFile($this->file("%PDF-1.4\n%%EOF"), 'Doc');

        self::assertSame('Doc', $document->title);
        self::assertSame(['type' => 'document', 'data' => 'JVBERi0xLjQKJSVFT0Y=', 'title' => 'Doc'], $document->toArray());
    }

    public function testDocumentFromFileRejectsNonPdf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Document::fromFile($this->file('not a pdf'));
    }

    public function testDocumentNeedsExactlyOneSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Document();
    }

    public function testToolCallOfEmptyArgumentsIsAnObject(): void
    {
        $call = ToolCall::of('id', 'name', []);

        self::assertSame('{}', $call->argumentsJson);
        self::assertSame([], $call->arguments());
        self::assertSame(['a' => 1], ToolCall::of('id', 'name', ['a' => 1])->arguments());
    }

    public function testToolCallRejectsNonObjectJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ToolCall('id', 'name', '[1,2]');
    }

    public function testToolCallRejectsInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ToolCall('id', 'name', '{');
    }

    public function testToolResultText(): void
    {
        $result = ToolResult::text('c', 'n', 'out', true);

        self::assertEquals([new Text('out')], $result->content);
        self::assertTrue($result->isError);
        self::assertSame('tool_result', $result->toArray()['type']);
    }

    public function testThinkingAndProviderPartToArray(): void
    {
        self::assertSame(
            ['type' => 'thinking', 'text' => 't', 'provider' => 'anthropic', 'opaque' => ['signature' => 's']],
            (new Thinking('t', 'anthropic', ['signature' => 's']))->toArray(),
        );
        self::assertSame(
            ['type' => 'provider', 'provider' => 'anthropic', 'json' => '{}'],
            (new ProviderPart('anthropic', '{}'))->toArray(),
        );
    }

    private function file(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ai');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    public function testProviderPartRejectsInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProviderPart('anthropic', '{"type":');
    }

    public function testProviderPartRejectsNonObjectJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProviderPart('anthropic', '[1]');
    }
}
