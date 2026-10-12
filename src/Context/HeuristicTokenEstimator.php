<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Context;

use Ldkafka\Ai\Message\Document;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Message;
use Ldkafka\Ai\Message\Part;
use Ldkafka\Ai\Message\ProviderPart;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Message\Thinking;
use Ldkafka\Ai\Message\ToolCall;
use Ldkafka\Ai\Message\ToolResult;

use function base64_decode;
use function ceil;
use function max;
use function preg_match_all;
use function preg_replace;
use function strlen;

/**
 * An offline approximation: characters / 2.5 for text (the current Anthropic tokenizer averages
 * about 2.5 characters per token), a flat count per image, and a count per PDF page. A cost knob,
 * not a guarantee: the provider's "prompt is too long" error stays the backstop.
 */
final class HeuristicTokenEstimator implements TokenEstimatorInterface
{
    /**
     * @param float $charsPerToken Characters per token for text.
     * @param int $imageTokens Tokens per image.
     * @param int $pdfPageTokens Tokens per PDF page.
     */
    public function __construct(
        private float $charsPerToken = 2.5,
        private int $imageTokens = 1600,
        private int $pdfPageTokens = 3000,
    ) {
    }

    /**
     * Estimated tokens of one message.
     */
    public function message(Message $message): int
    {
        $tokens = 0;
        foreach ($message->parts as $part) {
            $tokens += $this->part($part);
        }

        return $tokens;
    }

    /**
     * Estimated tokens of a text: UTF-8 characters / charsPerToken, rounded up.
     */
    public function text(string $text): int
    {
        // Count characters without mbstring: drop the UTF-8 continuation bytes.
        $characters = strlen((string) preg_replace('/[\x80-\xBF]/', '', $text));

        return (int) ceil($characters / $this->charsPerToken);
    }

    /**
     * Estimated tokens of one part.
     */
    private function part(Part $part): int
    {
        return match (true) {
            $part instanceof Text => $this->text($part->text),
            $part instanceof Image => $this->imageTokens,
            $part instanceof Document => $this->pages($part) * $this->pdfPageTokens,
            $part instanceof ToolCall => $this->text($part->name . $part->argumentsJson),
            $part instanceof ToolResult => $this->toolResult($part),
            $part instanceof Thinking => $this->text($part->text),
            $part instanceof ProviderPart => $this->text($part->json),
            default => 0,
        };
    }

    /**
     * Estimated tokens of a tool result: the sum of its content.
     */
    private function toolResult(ToolResult $result): int
    {
        $tokens = 0;
        foreach ($result->content as $part) {
            $tokens += $this->part($part);
        }

        return $tokens;
    }

    /**
     * Pages of a PDF from its `/Type /Page` objects; one for a URL.
     */
    private function pages(Document $document): int
    {
        if ($document->data === null) {
            return 1;
        }
        $bytes = base64_decode($document->data, true);
        if ($bytes === false) {
            return 1;
        }

        return max(1, (int) preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $bytes));
    }
}
