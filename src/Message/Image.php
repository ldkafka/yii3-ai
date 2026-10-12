<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Message;

use InvalidArgumentException;

use function base64_encode;
use function in_array;
use function is_file;
use function is_readable;
use function file_get_contents;
use function str_starts_with;
use function substr;

/**
 * An image: exactly one of base64 `$data` or `$url`. Media types: jpeg, png, gif, webp.
 */
final readonly class Image implements Part
{
    public const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * @param string $mediaType One of {@see MEDIA_TYPES}; may be '' for a URL.
     * @param string|null $data Base64 image bytes.
     * @param string|null $url A public https URL.
     * @throws InvalidArgumentException When both or neither source is given, or the media type is not supported.
     */
    public function __construct(public string $mediaType, public ?string $data = null, public ?string $url = null)
    {
        if (($data === null) === ($url === null)) {
            throw new InvalidArgumentException('An Image needs exactly one of data or url.');
        }
        if (($data !== null || $mediaType !== '') && !in_array($mediaType, self::MEDIA_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported image media type "' . $mediaType . '".');
        }
    }

    /**
     * Read an image file; the media type is detected from its first bytes.
     *
     * @throws InvalidArgumentException When the file cannot be read or is not jpeg, png, gif or webp.
     */
    public static function fromFile(string $path): self
    {
        $bytes = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new InvalidArgumentException('Cannot read image file "' . $path . '".');
        }
        $mediaType = self::detect($bytes);
        if ($mediaType === null) {
            throw new InvalidArgumentException('File "' . $path . '" is not a jpeg, png, gif or webp image.');
        }

        return new self($mediaType, base64_encode($bytes));
    }

    /**
     * An image the provider fetches itself.
     *
     * @param string $mediaType Optional; '' when unknown.
     */
    public static function fromUrl(string $url, string $mediaType = ''): self
    {
        return new self($mediaType, null, $url);
    }

    /**
     * The media type of image bytes from their signature, or null.
     */
    public static function detect(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /**
     * @return array{type: 'image', mediaType: string, data?: string, url?: string}
     */
    public function toArray(): array
    {
        $out = ['type' => 'image', 'mediaType' => $this->mediaType];
        if ($this->data !== null) {
            $out['data'] = $this->data;
        }
        if ($this->url !== null) {
            $out['url'] = $this->url;
        }

        return $out;
    }
}
