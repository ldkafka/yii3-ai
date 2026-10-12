<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Mcp;

use InvalidArgumentException;
use Ldkafka\Ai\Exception\McpException;
use Ldkafka\Ai\Exception\ToolException;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Message\Image;
use Ldkafka\Ai\Message\Text;
use Ldkafka\Ai\Tool\ToolInterface;
use stdClass;

use function array_key_exists;
use function base64_decode;
use function implode;
use function in_array;
use function is_array;

/**
 * One tool on a remote MCP server. handle() calls `tools/call`.
 *
 * Text content becomes Text, image content Image (base64 checked; other media types become a note);
 * other content types become a short Text note.
 * An `isError` result and a JSON-RPC "Invalid params" error become a ToolException with the
 * server's text, which the model reads. Other failures propagate (the Runner reports them as
 * "Tool X failed" and logs them locally).
 */
final class RemoteMcpTool implements ToolInterface
{
    /**
     * @param McpHttpClient $client The server connection.
     * @param string $name The tool name on the server.
     * @param string $description What the tool does.
     * @param array<string, mixed> $inputSchema The JSON Schema of the arguments; empty objects stay \stdClass.
     */
    public function __construct(
        private McpHttpClient $client,
        private string $name,
        private string $description,
        private array $inputSchema,
    ) {
    }

    /**
     * A tool from one `tools/list` entry.
     *
     * @param array<string, mixed> $definition
     * @throws InvalidArgumentException When the entry has no name.
     */
    public static function fromDefinition(McpHttpClient $client, array $definition): self
    {
        $name = Json::string($definition, 'name');
        if ($name === null || $name === '') {
            throw new InvalidArgumentException('An MCP tool definition needs a name.');
        }
        $schema = $definition['inputSchema'] ?? null;
        $inputSchema = [];
        foreach (is_array($schema) ? $schema : [] as $key => $value) {
            $inputSchema[(string) $key] = $value;
        }
        if ($inputSchema === []) {
            $inputSchema = ['type' => 'object', 'properties' => new stdClass()];
        }

        return new self(
            $client,
            $name,
            Json::string($definition, 'description') ?? Json::string($definition, 'title') ?? '',
            $inputSchema,
        );
    }

    /**
     * The tool name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The server's description.
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * The server's input schema.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    /**
     * Call the remote tool.
     *
     * @param array<string, mixed> $arguments
     * @return string|list<Text|Image>
     * @throws ToolException On an isError result or rejected arguments.
     * @throws McpException On any other failure.
     */
    public function handle(array $arguments): string|array
    {
        try {
            $result = $this->client->callTool($this->name, $arguments);
        } catch (McpException $e) {
            if ($e->rpcCode === McpHttpClient::INVALID_PARAMS) {
                throw new ToolException($e->getMessage(), 0, $e);
            }
            throw $e;
        }

        $parts = [];
        foreach (Json::array($result, 'content') as $block) {
            if (is_array($block)) {
                $parts[] = $this->part($block);
            }
        }
        if ($parts === [] && array_key_exists('structuredContent', $result)) {
            $parts[] = new Text(Json::encode($result['structuredContent']));
        }

        $texts = [];
        $allText = true;
        foreach ($parts as $part) {
            if ($part instanceof Text) {
                $texts[] = $part->text;
            } else {
                $allText = false;
            }
        }

        if (($result['isError'] ?? false) === true) {
            $text = implode("\n", $texts);
            throw new ToolException($text !== '' ? $text : 'Tool ' . $this->name . ' returned an error.');
        }

        return $allText ? implode("\n", $texts) : $parts;
    }

    /**
     * One MCP content block as a part.
     *
     * @param array<mixed> $block
     * @throws ToolException When image data is not valid base64.
     */
    private function part(array $block): Text|Image
    {
        $type = Json::string($block, 'type') ?? '';
        switch ($type) {
            case 'text':
                return new Text(Json::string($block, 'text') ?? '');
            case 'image':
                $mime = Json::string($block, 'mimeType') ?? '';
                $data = Json::string($block, 'data') ?? '';
                if (!in_array($mime, Image::MEDIA_TYPES, true)) {
                    return new Text('[' . ($mime !== '' ? $mime : 'image') . ' image omitted]');
                }
                $bytes = base64_decode($data, true);
                if ($bytes === false || $bytes === '') {
                    throw new ToolException('Tool ' . $this->name . ' returned image data that is not valid base64.');
                }

                return new Image($mime, $data);
            case 'resource':
                $resource = Json::array($block, 'resource');
                $text = Json::string($resource, 'text');
                if ($text !== null) {
                    return new Text($text);
                }

                return new Text('[resource ' . (Json::string($resource, 'uri') ?? '') . ' omitted]');
            case 'resource_link':
                return new Text('Resource: ' . (Json::string($block, 'uri') ?? ''));
            default:
                return new Text('[' . ($type !== '' ? $type : 'unknown') . ' content omitted]');
        }
    }
}
