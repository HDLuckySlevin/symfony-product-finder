<?php
declare(strict_types=1);

namespace App\Service\rag\response;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAIResponseBasicService
{
    /** @var string[] */
    private array $vectorStoreIds = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private ?string $apiBase = null,
        private readonly string $defaultModel = 'gpt-5-mini',
        string $vectorStoreIdsCsv = '',
        private readonly ?LoggerInterface $logger = null,
        private readonly string $fallbackModel = 'gpt-5-mini',
        private readonly ?int $defaultMaxOutputTokens = null,
        private readonly ?float $defaultTemperature = null,
    ) {
        $apiBase = rtrim($apiBase ?: 'https://api.openai.com/v1', '/');
        $this->apiBase = $apiBase;
        $this->vectorStoreIds = array_values(array_filter(array_map('trim', explode(',', (string)$vectorStoreIdsCsv))));
    }

    public function ping(int $timeoutSeconds = 5): int
    {
        $t0 = microtime(true);
        try {
            $res = $this->httpClient->request('GET', $this->apiBase . '/models', [
                'headers'      => $this->authHeaders(),
                'timeout'      => $timeoutSeconds,
                'http_version' => '2.0',
            ]);
            $res->getHeaders(false);
            $ms = (int) ((microtime(true) - $t0) * 1000);
            $this->logger?->debug('openai.basic.ping', ['ms' => $ms]);
            return $ms;
        } catch (\Throwable) {
            return -1;
        }
    }

    public function createResponse(
        string $userText,
        ?UploadedFile $image = null,
        ?string $model = null,
        ?array $prompt = null,
        ?string $previousResponseId = null,
        ?array $extra = null
    ): array {
        $payload = $this->buildPayload($userText, $image, $model, $prompt, $previousResponseId, $extra);

        $makeRequest = function(array $payload) {
            return $this->httpClient->request('POST', $this->apiBase . '/responses', [
                'headers'      => $this->authHeaders(),
                'json'         => $payload,
                'timeout'      => 60,
                'http_version' => '2.0',
            ]);
        };

        $this->logger?->info('openai.basic.request_full', ['payload' => $this->redact($payload)]);
        $res = $makeRequest($payload);
        $status = $res->getStatusCode();
        $raw    = $res->getContent(false);

        if ($status >= 400 && ($payload['model'] ?? '') === $this->defaultModel) {
            $decoded = json_decode($raw, true);
            $errType = $decoded['error']['type'] ?? '';
            if ($status === 404 || $errType === 'model_not_found') {
                $payload['model'] = $this->fallbackModel;
                $this->logger?->debug('openai.basic.request_fallback', ['model' => $this->fallbackModel]);
                $res = $makeRequest($payload);
                $status = $res->getStatusCode();
                $raw    = $res->getContent(false);
            }
        }

        if ($status >= 400 && isset($payload['temperature'])) {
            $decoded = json_decode($raw, true);
            if ($this->isUnsupportedTemperatureError($decoded)) {
                unset($payload['temperature']);
                $this->logger?->debug('openai.basic.retry_without_temperature');
                $res = $makeRequest($payload);
                $status = $res->getStatusCode();
                $raw    = $res->getContent(false);
            }
        }

        $this->logger?->info('openai.basic.response_full', ['status' => $status, 'raw' => $raw]);
        if ($status >= 400) {
            $this->logger?->error('openai.basic.http_error', ['status' => $status, 'raw' => $raw]);
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['status' => 'error', 'http_status' => $status, 'raw' => $raw];
    }

    private function buildPayload(
        string $userText,
        ?UploadedFile $image = null,
        ?string $model = null,
        ?array $prompt = null,
        ?string $previousResponseId = null,
        ?array $extra = null
    ): array {
        $modelName = $model ?: ($this->defaultModel ?: 'gpt-5-mini');
        $content = [
            ['type' => 'input_text', 'text' => $userText],
        ];
        if ($image) {
            $mime = $image->getMimeType() ?: 'application/octet-stream';
            $b64 = base64_encode((string) file_get_contents($image->getPathname()));
            $content[] = ['type' => 'input_image', 'image_url' => sprintf('data:%s;base64,%s', $mime, $b64)];
        }

        $payload = [
            'model' => $modelName,
            'input' => [[ 'role' => 'user', 'content' => $content ]],
            'tool_choice' => 'auto',
        ];
        // No temperature or max_output_tokens by default
        if (is_array($prompt) && !empty($prompt['id'])) {
            $payload['prompt'] = ['id' => (string)$prompt['id']];
            if (!empty($prompt['variables'])) {
                $payload['prompt']['variables'] = (array)$prompt['variables'];
            }
            if (!empty($prompt['version'])) {
                $payload['prompt']['version'] = (string)$prompt['version'];
            }
        }
        if (!empty($this->vectorStoreIds)) {
            $payload['tools'][] = [
                'type' => 'file_search',
                'vector_store_ids' => $this->vectorStoreIds,
            ];
        }
        if (!empty($previousResponseId)) {
            $payload['previous_response_id'] = $previousResponseId;
        }
        if (!empty($extra) && is_array($extra)) {
            $payload = array_replace_recursive($payload, $extra);
        }
        return $payload;
    }

    public static function extractText(array $response): string
    {
        return OpenAIResponseService::extractText($response);
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ];
    }

    private function redact(array $payload): array
    {
        $copy = $payload;
        if (isset($copy['input'][0]['content'])) {
            foreach ($copy['input'][0]['content'] as &$part) {
                if (($part['type'] ?? null) === 'input_image' && isset($part['image_url'])) {
                    $part['image_url'] = '[redacted-data-url]';
                }
            }
        }
        return $copy;
    }

    private function isUnsupportedTemperatureError(mixed $decoded): bool
    {
        if (!is_array($decoded)) { return false; }
        $msg = (string)($decoded['error']['message'] ?? '');
        if ($msg === '') { return false; }
        $msgLower = mb_strtolower($msg);
        return str_contains($msgLower, 'unsupported parameter') && str_contains($msgLower, 'temperature');
    }
}
