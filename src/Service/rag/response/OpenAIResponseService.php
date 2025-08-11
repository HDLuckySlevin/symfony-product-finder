<?php
declare(strict_types=1);

namespace App\Service\rag\response;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAIResponseService
{
    /** @var string[] */
    private array $vectorStoreIds = [];

    private HttpClientInterface $httpClient;
    private string $apiKey;
    private string $apiBase;
    private string $defaultModel;
    private ?LoggerInterface $logger;

    public function __construct(
        HttpClientInterface $httpClient,
        string $apiKey,
        ?string $apiBase = null,
        string $defaultModel = 'gpt-4.1',
        string $vectorStoreIdsCsv = '',
        ?LoggerInterface $logger = null
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        $this->apiBase = rtrim($apiBase ?: 'https://api.openai.com/v1', '/');
        $this->defaultModel = $defaultModel;
        $this->vectorStoreIds = array_values(array_filter(array_map('trim', explode(',', (string)$vectorStoreIdsCsv))));
        $this->logger = $logger;
    }

    public function createResponse(
        string $userText,
        ?UploadedFile $image = null,
        ?string $model = null,
        ?array $prompt = null,                 // ['id' => 'pmpt_...', 'variables' => [...], (optional) 'version' => '1']
        ?string $previousResponseId = null,    // resp_... (für Mehr-Turn)
        ?array $extra = null                   // optionale Payload-Overrides
    ): array {
        $model = $model ?: $this->defaultModel;

        // --- Content bauen (Text + optional Bild als data URL) ---
        $content = [['type' => 'input_text', 'text' => $userText]];
        if ($image) {
            $mime = $image->getMimeType() ?: 'application/octet-stream';
            if (!in_array($mime, ['image/png','image/jpeg','image/webp'], true)) {
                throw new \InvalidArgumentException('Unsupported image type.');
            }
            $maxBytes = 8 * 1024 * 1024;
            if ($image->getSize() > $maxBytes) {
                throw new \InvalidArgumentException('Image too large (max 8 MB).');
            }
            $b64 = base64_encode((string) file_get_contents($image->getPathname()));
            $dataUrl = sprintf('data:%s;base64,%s', $mime, $b64);
            $content[] = ['type' => 'input_image', 'image_url' => $dataUrl];
        }

        $payload = [
            'model' => 'gpt-5-mini',
            'prompt' => [
                'id'        => 'pmpt_6895d4b9f5188195b3c079b27f14d95f099da37685d16022',
                'variables' => ['customer_name' => 'Kunde'],
            ],
            'input' => [[
                'role'    => 'user',
                'content' => $content,
            ]],
            'tools' => [[
                'type'             => 'file_search',
                'vector_store_ids' => $this->vectorStoreIds,
                'max_num_results'  => 20,
            ]],
            'tool_choice' => 'auto', // wichtig bei file_search
        ];

        // Prompt einfügen, falls übergeben
        if (!empty($prompt['id'])) {
            $payload['prompt'] = ['id' => (string) $prompt['id']];
            if (!empty($prompt['variables']) && is_array($prompt['variables'])) {
                $payload['prompt']['variables'] = $prompt['variables'];
            }
            if (!empty($prompt['version'])) {
                $payload['prompt']['version'] = (string) $prompt['version'];
            }
        }

        // previous_response_id für Multi-Turn setzen (optional)
        if (!empty($previousResponseId)) {
            $payload['previous_response_id'] = $previousResponseId;
        }

        // optionale Overrides mergen (z. B. metadata) – KEINE nicht unterstützten Felder wie temperature/top_p hinzufügen
        if ($extra) {
            $payload = array_replace_recursive($payload, $extra);
        }

        // --- Logging & Request ---
        $t0 = microtime(true);
        $this->logDebug('openai.request', [
            'endpoint' => '/responses',
            'model' => $model,
            'has_prompt' => isset($payload['prompt']),
            'prev_id' => $payload['previous_response_id'] ?? null,
            'vector_store_ids' => $this->vectorStoreIds,
            'has_image' => (bool) $image,
            'payload' => $this->redact($payload),
        ]);

        $res = $this->httpClient->request('POST', $this->apiBase . '/responses', [
            'headers' => $this->authHeaders(),
            'json'    => $payload,
            'timeout' => 60,
        ]);

        $status = $res->getStatusCode();
        $raw    = $res->getContent(false);
        $info   = $res->getInfo();

        $this->logDebug('openai.response', [
            'status' => $status,
            'duration_ms' => (int) ((microtime(true) - $t0) * 1000),
            'url' => $info['url'] ?? null,
            'response_headers' => $info['response_headers'] ?? [],
            'raw_preview' => mb_substr($raw, 0, 500),
        ]);

        // --- Robust gegen Nicht-JSON ---
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError('openai.json_decode_failed', [
                'error' => json_last_error_msg(),
                'status' => $status,
                'raw_head' => mb_substr($raw, 0, 2000),
            ]);
            return [
                'status' => 'error',
                'http_status' => $status,
                'error' => [
                    'type' => 'json_decode_error',
                    'message' => json_last_error_msg(),
                ],
                'raw' => mb_substr($raw, 0, 4000),
            ];
        }

        return $decoded;
    }




    public function getResponse(string $responseId): array
    {
        $t0 = microtime(true);
        $this->logDebug('openai.get_request', ['id' => $responseId]);

        $res = $this->httpClient->request('GET', $this->apiBase . '/responses/' . urlencode($responseId), [
            'headers' => $this->authHeaders(),
            'timeout' => 30,
        ]);
        $status = $res->getStatusCode();
        $raw = $res->getContent(false);

        $this->logDebug('openai.get_response', [
            'id' => $responseId,
            'status' => $status,
            'duration_ms' => (int)((microtime(true) - $t0) * 1000),
            'raw_preview' => mb_substr($raw, 0, 500),
        ]);

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['status' => 'error', 'http_status' => $status, 'raw' => $raw];
    }

    public function deleteResponse(string $responseId): array
    {
        $t0 = microtime(true);
        $this->logDebug('openai.delete_request', ['id' => $responseId]);

        $res = $this->httpClient->request('DELETE', $this->apiBase . '/responses/' . urlencode($responseId), [
            'headers' => $this->authHeaders(),
            'timeout' => 30,
        ]);
        $status = $res->getStatusCode();
        $raw = $res->getContent(false);

        $this->logDebug('openai.delete_response', [
            'id' => $responseId,
            'status' => $status,
            'duration_ms' => (int)((microtime(true) - $t0) * 1000),
            'raw_preview' => mb_substr($raw, 0, 500),
        ]);

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['status' => 'error', 'http_status' => $status, 'raw' => $raw];
    }

    public static function extractText(array $response): string
    {
        if (($response['status'] ?? '') === 'error' && isset($response['error']['message'])) {
            return 'Fehler: ' . (string)$response['error']['message'];
        }
        $chunks = [];
        foreach (($response['output'] ?? []) as $message) {
            foreach (($message['content'] ?? []) as $c) {
                if (($c['type'] ?? '') === 'output_text' && isset($c['text'])) {
                    $chunks[] = (string)$c['text'];
                }
            }
        }
        return trim(implode("\n", $chunks));
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

    private function logDebug(string $event, array $context = []): void
    {
        $this->logger?->debug($event, $context);
    }

    private function logError(string $event, array $context = []): void
    {
        $this->logger?->error($event, $context);
    }
}
