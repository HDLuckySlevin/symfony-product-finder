<?php

namespace App\Service\rag;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RagOpenAiService
{
    private HttpClientInterface $client;
    private string $apiKey;
    private string $baseUrl;
    private string $assistantId;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
        $this->apiKey = $_ENV['OPENAI_API_KEY'];
        $this->baseUrl = rtrim($_ENV['OPENAI_API_BASE_RAG'], '/');
        $this->assistantId = $_ENV['OPENAI_ASSISTANT_ID'];
    }

    private function request(string $method, string $endpoint, array $body = []): array
    {
        try {
            $response = $this->client->request($method, $this->baseUrl . $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'OpenAI-Beta' => 'assistants=v2',
                    'Content-Type' => 'application/json',
                ],
                'json' => $body ?: null,
            ]);

            return $response->toArray(false);
        } catch (\Exception $e) {
            throw new HttpException(500, 'OpenAI API Error: ' . $e->getMessage(), $e);
        }
    }

    public function createThread(): string
    {
        $data = $this->request('POST', '/threads');
        return $data['id'];
    }

    public function sendMessage(string $threadId, string $content): string
    {
        $data = $this->request('POST', "/threads/{$threadId}/messages", [
            'role' => 'user',
            'content' => $content,
        ]);
        return $data['id'];
    }

    public function startRun(string $threadId): string
    {
        $data = $this->request('POST', "/threads/{$threadId}/runs", [
            'assistant_id' => $this->assistantId,
        ]);
        return $data['id'];
    }

    public function getRunStatus(string $threadId, string $runId): string
    {
        $data = $this->request('GET', "/threads/{$threadId}/runs/{$runId}");
        return $data['status'];
    }

    public function getMessages(string $threadId): array
    {
        $data = $this->request('GET', "/threads/{$threadId}/messages");
        return $data['data'] ?? [];
    }

    public function deleteThread(string $threadId): void
    {
        $this->request('DELETE', "/threads/{$threadId}");
    }
}
