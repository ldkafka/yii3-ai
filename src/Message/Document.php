<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Message;

use InvalidArgumentException;

use function base64_encode;
use function file_get_contents;
use function is_file;
use function is_readable;
use function str_starts_with;

/**
 * A PDF: exactly one of base64 `$data` or `$url`.
 */
final readonly class Document implements Part
{
    /**
     * @param string|null $data Base64 PDF bytes.
     * @param string|null $url A public https URL.
     * @param string|null $title Optional title shown to the model.
     * @throws InvalidArgumentException When both or neither source is given.
     */
    public function __construct(public ?string $data = null, public ?string $url = null, public ?string $title = null)
    {
        if (($data === null) === ($url === null)) {
            throw new InvalidArgumentException('A Document needs exactly one of data or url.');
        }
    }

    /**
     * Read a PDF file.
     *
     * @throws InvalidArgumentException When the file cannot be read or is not a PDF.
     */
    public static function fromFile(string $path, ?string $title = null): self
    {
        $bytes = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new InvalidArgumentException('Cannot read document file "' . $path . '".');
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new InvalidArgumentException('File "' . $path . '" is not a PDF.');
        }

        return new self(base64_encode($bytes), null, $title);
    }

    /**
     * A PDF the provider fetches itself.
     */
    public static function fromUrl(string $url, ?string $title = null): self
    {
        return new self(null, $url, $title);
    }

    /**
     * @return array{type: 'document', data?: string, url?: string, title?: string}
     */
    public function toArray(): array
    {
        $out = ['type' => 'document'];
        if ($this->data !== null) {
            $out['data'] = $this->data;
        }
        if ($this->url !== null) {
            $out['url'] = $this->url;
        }
        if ($this->title !== null) {
            $out['title'] = $this->title;
        }

        return $out;
    }
}
