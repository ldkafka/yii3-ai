<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Provider\Anthropic;

use JsonException;
use Ldkafka\Ai\Client;
use Ldkafka\Ai\Exception\ConfigurationException;
use Ldkafka\Ai\Http\HttpRequest;
use Ldkafka\Ai\Http\TransportInterface;
use Ldkafka\Ai\Internal\Json;
use Ldkafka\Ai\Model\ModelProfile;
use Ldkafka\Ai\Provider\ProviderInterface;
use Ldkafka\Ai\Request;
use Ldkafka\Ai\Response;

use function array_unique;
use function array_values;
use function implode;
use function rtrim;

/**
 * The Anthropic Messages API (`POST /v1/messages`), non-streaming. One attempt per call.
 */
final class AnthropicProvider implements ProviderInterface
{
    public const NAME = 'anthropic';

    /**
     * @param string $apiKey The API key; '' fails at the first call.
     * @param TransportInterface $transport Sends the HTTP request.
     * @param string $baseUrl The API base URL.
     * @param string $version The anthropic-version header.
     * @param list<string> $betas Sent in the anthropic-beta header on every call.
     * @param AnthropicMapper $mapper The payload and response mapper.
     * @param string|null $workspaceId Sent as anthropic-workspace-id when set (multi-workspace keys).
     */
    public function __construct(
        #[\SensitiveParameter] private string $apiKey,
        private TransportInterface $transport,
        private string $baseUrl = 'https://api.anthropic.com',
        private string $version = '2023-06-01',
        private array $betas = [],
        private AnthropicMapper $mapper = new AnthropicMapper(),
        private ?string $workspaceId = null,
    ) {
    }

    /**
     * Send one Messages API call.
     */
    public function send(Request $request, ModelProfile $model): Response
    {
        if ($this->apiKey === '') {
            throw new ConfigurationException('The Anthropic API key is not set (params apiKey or ANTHROPIC_API_KEY).');
        }

        $headers = [
            'authorization' => 'Bearer ' . $this->apiKey,
            'anthropic-version' => $this->version,
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'user-agent' => 'ldkafka-yii3-ai/' . Client::VERSION,
        ];
        $betas = array_values(array_unique([...$this->betas, ...$this->mapper->betas($request)]));
        if ($betas !== []) {
            $headers['anthropic-beta'] = implode(',', $betas);
        }
        if ($this->workspaceId !== null && $this->workspaceId !== '') {
            $headers['anthropic-workspace-id'] = $this->workspaceId;
        }

        try {
            $body = Json::encode($this->mapper->toPayload($request, $model));
        } catch (JsonException $e) {
            throw new ConfigurationException('The request cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }

        $response = $this->transport->send(new HttpRequest('POST', rtrim($this->baseUrl, '/') . '/v1/messages', $headers, $body));

        if ($response->status < 200 || $response->status >= 300) {
            throw $this->mapper->toException($response);
        }

        return $this->mapper->toResponse($response->body, $model, $response->header('request-id'));
    }

    /**
     * The fields for var_dump() and print_r(), without the API key.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '[redacted]',
            'transport' => $this->transport::class,
            'baseUrl' => $this->baseUrl,
            'version' => $this->version,
            'betas' => $this->betas,
            'workspaceId' => $this->workspaceId,
        ];
    }
}
