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
    private string $fallbackModel;
    private ?LoggerInterface $logger;
    private ?int $defaultMaxOutputTokens = null;
    private ?float $defaultTemperature = null;

    public function __construct(
        HttpClientInterface $httpClient,
        string $apiKey,
        ?string $apiBase = null,
        string $defaultModel = 'gpt-5-mini',
        string $vectorStoreIdsCsv = '',
        ?LoggerInterface $logger = null,
        string $fallbackModel = 'gpt-5',
        ?int $defaultMaxOutputTokens = null,
        ?float $defaultTemperature = null
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        $this->apiBase = rtrim($apiBase ?: 'https://api.openai.com/v1', '/');
        $this->defaultModel = $defaultModel;
        $this->fallbackModel = $fallbackModel;
        $this->vectorStoreIds = array_values(array_filter(array_map('trim', explode(',', (string)$vectorStoreIdsCsv))));
        $this->logger = $logger;
        $this->defaultMaxOutputTokens = $defaultMaxOutputTokens;
        $this->defaultTemperature = $defaultTemperature;
    }

    // ping removed as requested

    public function createResponse(
        string $userText,
        ?UploadedFile $image = null,
        ?string $model = null,
        ?array $prompt = null,                 // ['id' => 'pmpt_...', 'variables' => [...], (optional) 'version' => '1']
        ?string $previousResponseId = null,    // resp_... (für Mehr-Turn)
        ?array $extra = null                   // optionale Payload-Overrides
    ): array {
        $payload = $this->buildPayload($userText, $image, $model, $prompt, $previousResponseId, $extra);

        $t0 = microtime(true);
        $makeRequest = function(array $payload) {
            return $this->httpClient->request('POST', $this->apiBase . '/responses', [
                'headers'      => $this->authHeaders(),
                'json'         => $payload,
                'timeout'      => 60,
                'http_version' => '2.0',
            ]);
        };

        $logRequest = function(array $payload) {
            $this->logDebug('openai.request', [
                'endpoint' => '/responses',
                'model' => $payload['model'] ?? null,
                'has_prompt' => isset($payload['prompt']),
                'prev_id' => $payload['previous_response_id'] ?? null,
                'vector_store_ids' => $this->vectorStoreIds,
                'has_image' => !empty($payload['input'][0]['content']) && count($payload['input'][0]['content']) > 1,
                'payload' => $this->redact($payload),
            ]);
        };

        $logRequest($payload);
        $res = $makeRequest($payload);
        $status = $res->getStatusCode();
        $raw    = $res->getContent(false);

        // Retry with fallback model if model was not found
        if ($status >= 400 && ($payload['model'] ?? '') === $this->defaultModel) {
            $decoded = json_decode($raw, true);
            $errType = $decoded['error']['type'] ?? '';
            if ($status === 404 || $errType === 'model_not_found') {
                $payload['model'] = $this->fallbackModel;
                $this->logDebug('openai.request_fallback', ['model' => $this->fallbackModel]);
                $logRequest($payload);
                $res = $makeRequest($payload);
                $status = $res->getStatusCode();
                $raw    = $res->getContent(false);
            }
        }

        // Retry without unsupported parameters (temperature)
        if ($status >= 400 && isset($payload['temperature'])) {
            $decoded = json_decode($raw, true);
            if ($this->isUnsupportedTemperatureError($decoded)) {
                unset($payload['temperature']);
                $this->logDebug('openai.request_retry_without_temperature');
                $logRequest($payload);
                $res = $makeRequest($payload);
                $status = $res->getStatusCode();
                $raw    = $res->getContent(false);
            }
        }
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

    public function createResponseStream(
        string $userText,
        ?UploadedFile $image = null,
        ?string $model = null,
        ?array $prompt = null,
        ?string $previousResponseId = null,
        ?array $extra = null,
        callable $onEvent = null
    ): void {
        $payload = $this->buildPayload($userText, $image, $model, $prompt, $previousResponseId, $extra);
        $payload['stream'] = true;

        $headers = $this->authHeaders();
        $headers['Accept'] = 'text/event-stream';

        $makeRequest = function(array $payload) use ($headers) {
            $this->logDebug('stream.request', [
                'endpoint' => '/responses',
                'model' => $payload['model'] ?? null,
                'payload' => $this->redact($payload),
            ]);
            return $this->httpClient->request('POST', $this->apiBase . '/responses', [
                'headers'      => $headers,
                'json'         => $payload,
                'timeout'      => 600,
                'max_duration' => 0,
                'buffer'       => false,
                'http_version' => '2.0',
            ]);
        };

        $response = $makeRequest($payload);
        $status = $response->getStatusCode();
        if ($status >= 400 && ($payload['model'] ?? '') === $this->defaultModel) {
            $payload['model'] = $this->fallbackModel;
            $this->logDebug('stream.request_fallback', ['model' => $this->fallbackModel, 'status' => $status]);
            $response = $makeRequest($payload);
            $status = $response->getStatusCode();
        }

        if ($status >= 400) {
            $raw = $response->getContent(false);
            $decoded = json_decode($raw, true);
            // Retry without unsupported parameters (temperature)
            if (isset($payload['temperature']) && $this->isUnsupportedTemperatureError($decoded)) {
                unset($payload['temperature']);
                $this->logDebug('stream.request_retry_without_temperature', ['status' => $status]);
                $response = $makeRequest($payload);
                $status = $response->getStatusCode();
            }

            if ($status >= 400) {
                $raw = $response->getContent(false);
                $this->logError('stream.request_failed', [
                    'status' => $status,
                    'raw_preview' => mb_substr($raw, 0, 500),
                ]);
                $decoded = json_decode($raw, true);
                $message = $decoded['error']['message'] ?? ('HTTP error ' . $status);
                throw new \RuntimeException($message, $status);
            }
        }

        $buffer = '';
        try {
            foreach ($this->httpClient->stream($response, 600.0) as $chunk) {
                if ($chunk->isTimeout()) { continue; }
                if ($chunk->isFirst()) {
                    $this->logDebug('stream.connected');
                }
                if ($chunk->isLast()) {
                    $buffer .= $chunk->getContent();
                    $this->logDebug('stream.last_chunk', ['bytes' => strlen($buffer)]);
                    $this->drainSseBuffer($buffer, $onEvent);
                    break;
                }
                $piece = $chunk->getContent();
                $buffer .= $piece;
                $this->logDebug('stream.chunk', ['bytes' => strlen($piece)]);
                $this->drainSseBuffer($buffer, $onEvent);
            }
            $this->logDebug('stream.closed');
        } catch (\Throwable $e) {
            $this->logError('stream.exception', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function drainSseBuffer(string &$buffer, ?callable $onEvent): void
    {
        // Normalize newlines to \n
        $buffer = str_replace("\r\n", "\n", $buffer);
        while (true) {
            $pos = strpos($buffer, "\n\n");
            if ($pos === false) { break; }
            $rawEvent = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            $lines = explode("\n", $rawEvent);
            $eventName = null;
            $dataParts = [];
            foreach ($lines as $line) {
                if ($line === '') { continue; }
                if (str_starts_with($line, 'event:')) {
                    $eventName = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $part = ltrim(substr($line, 5));
                    if ($part === '' || $part === 'null') { continue; }
                    $dataParts[] = $part;
                }
            }
            $dataJson = implode("\n", $dataParts);
            $this->logDebug('stream.event', [
                'event' => $eventName ?? 'message',
                'data_preview' => mb_substr($dataJson, 0, 400),
            ]);
            if ($onEvent) {
                $data = [];
                if ($dataJson !== '') {
                    $decoded = json_decode($dataJson, true);
                    $data = is_array($decoded) ? $decoded : ['raw' => $dataJson];
                }
                $onEvent($eventName ?? 'message', $data);
            }
        }
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

        // --- Content aufbauen: Text + optional Bild ---
        $content = [
            ['type' => 'input_text', 'text' => $userText],
        ];

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

            // Direkt als Data-URL (von der API akzeptiert)
            $content[] = ['type' => 'input_image', 'image_url' => $dataUrl];
            // Alternativ bei Bedarf:
            // $content[] = ['type' => 'input_image', 'image_url' => ['url' => $dataUrl]];
        }

        // --- Basis-Payload ---
        $payload = [
            'model' => $modelName,
            'input' => [[
                'role'    => 'user',
                'content' => $content,
            ]],
            "reasoning" => [ "effort" =>"low" ],
            'tool_choice' => 'auto',
            'temperature' => 0.2,
        ];

        // No temperature or max_output_tokens by default

        // --- Prompt nur anhängen, wenn eine gültige Prompt-ID übergeben wurde ---
        // Erwartete Form: ['id' => 'pmpt_xxx', 'variables' => [...], 'version' => '1']
        if (is_array($prompt)) {
            $promptId = isset($prompt['id']) ? (string) $prompt['id'] : '';
            if ($promptId !== '') {
                $payload['prompt'] = ['id' => $promptId];

                if (!empty($prompt['variables']) && is_array($prompt['variables'])) {
                    $vars = [];
                    foreach ($prompt['variables'] as $k => $v) {
                        if (is_scalar($v) || $v === null) {
                            $vars[(string)$k] = $v === null ? '' : (string)$v;
                        }
                    }
                    if (!empty($vars)) {
                        $payload['prompt']['variables'] = $vars;
                    }
                }

                if (!empty($prompt['version'])) {
                    $payload['prompt']['version'] = (string) $prompt['version'];
                }
            }
        }

        // --- File Search nur anhängen, wenn Vector Stores vorhanden ---
        // Responses-API erwartet vector_store_ids IM Tool-Objekt, NICHT unter tool_resources.
        $tools = [];
        if (!empty($this->vectorStoreIds)) {
            $tools[] = [
                'type' => 'file_search',
                'vector_store_ids' => $this->vectorStoreIds,
                // optional:
                'max_num_results' => 8,
                // 'filters' => ['metadata' => ['key' => 'value']],
            ];
        }
        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        // --- Mehr-Turn-Kontext ---
        if (!empty($previousResponseId)) {
            $payload['previous_response_id'] = (string) $previousResponseId;
        }

        // --- Optionale Overrides ---
        if (!empty($extra) && is_array($extra)) {
            $payload = array_replace_recursive($payload, $extra);
        }

        return $payload;
    }

    public function getResponse(string $responseId): array
    {
        $t0 = microtime(true);
        $this->logDebug('openai.get_request', ['id' => $responseId]);

        $res = $this->httpClient->request('GET', $this->apiBase . '/responses/' . urlencode($responseId), [
            'headers'      => $this->authHeaders(),
            'timeout'      => 30,
            'http_version' => '2.0',
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
            'headers'      => $this->authHeaders(),
            'timeout'      => 30,
            'http_version' => '2.0',
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

    /**
     * Detect if decoded error array indicates unsupported 'temperature' parameter.
     * @param mixed $decoded
     */
    private function isUnsupportedTemperatureError(mixed $decoded): bool
    {
        if (!is_array($decoded)) { return false; }
        $msg = (string)($decoded['error']['message'] ?? '');
        if ($msg === '') { return false; }
        $msgLower = mb_strtolower($msg);
        return str_contains($msgLower, 'unsupported parameter') && str_contains($msgLower, 'temperature');
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
