<?php
declare(strict_types=1);

namespace App\Service\rag\response;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAIResponseAssistantService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private ?string $apiBase = null,
        private readonly ?string $assistantId = null,
        private readonly ?string $promptId = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?int $defaultMaxOutputTokens = 2000,
    ) {
        $apiBase = rtrim($apiBase ?: 'https://api.openai.com/v1', '/');
        $this->apiBase = $apiBase;
    }

    public function createResponse(
        string $userText,
        ?UploadedFile $image = null,
        ?array $promptVariables = null,
        ?array $extra = null
    ): array {
        $payload = $this->buildPayload($userText, $image, $promptVariables, $extra);
        $this->logger?->info('openai.assistant_resp.request_full', ['payload' => $this->redact($payload)]);

        $res = $this->httpClient->request('POST', $this->apiBase . '/responses', [
            'headers'      => $this->authHeaders(),
            'json'         => $payload,
            'timeout'      => 60,
            'http_version' => '2.0',
        ]);

        $status = $res->getStatusCode();
        $raw = $res->getContent(false);
        $this->logger?->info('openai.assistant_resp.response_full', ['status' => $status, 'raw' => $raw]);
        if ($status >= 400) {
            $this->logger?->error('openai.assistant_resp.http_error', ['status' => $status, 'raw' => $raw]);
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['status' => 'error', 'http_status' => $status, 'raw' => $raw];
    }

    private function buildPayload(
        string $userText,
        ?UploadedFile $image = null,
        ?array $promptVariables = null,
        ?array $extra = null
    ): array {
        $content = [ ['type' => 'input_text', 'text' => $userText] ];
        if ($image) {
            $mime = $image->getMimeType() ?: 'application/octet-stream';
            $b64 = base64_encode((string) file_get_contents($image->getPathname()));
            $content[] = ['type' => 'input_image', 'image_url' => sprintf('data:%s;base64,%s', $mime, $b64)];
        }

        $payload = [
            'input' => [[ 'role' => 'user', 'content' => $content ]],
            'assistant_id' => $this->assistantId,
            'tool_choice' => 'auto',
        ];
        if ($this->promptId) {
            $payload['prompt'] = [ 'id' => $this->promptId ];
            if (!empty($promptVariables)) {
                $payload['prompt']['variables'] = $promptVariables;
            }
        }
        // No temperature or max_output_tokens by default
        if (!empty($extra)) {
            $payload = array_replace_recursive($payload, $extra);
        }
        return $payload;
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
}
