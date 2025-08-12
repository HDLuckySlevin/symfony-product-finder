<?php

namespace App\Service\rag\assistant;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RagOpenAiService
{
    private HttpClientInterface $client;
    private string $apiKey;
    private string $baseUrl;
    private string $assistantId;
    private string $promptId;
    private ?LoggerInterface $logger = null;

    public function __construct(HttpClientInterface $client, ?LoggerInterface $logger = null)
    {
        $this->client = $client;
        $this->apiKey = $_ENV['OPENAI_API_KEY'];
        $this->baseUrl = rtrim($_ENV['OPENAI_API_BASE_RAG'], '/');
        $this->assistantId = $_ENV['OPENAI_ASSISTANT_ID'];
        $this->promptId = $_ENV['RAG_RESPONSES_PROMPT_ID'];
        $this->logger = $logger;
    }

    private function request(string $method, string $endpoint, array $body = []): array
    {
        try {
            $payload = $body ?: null;
            $this->logger?->debug('openai.assistants.request', [
                'method' => $method,
                'endpoint' => $endpoint,
                'payload' => $payload,
            ]);

            $response = $this->client->request($method, $this->baseUrl . $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'OpenAI-Beta' => 'assistants=v2',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
            $this->logger?->debug('openai.assistants.response', [
                'status' => $status,
                'raw_preview' => mb_substr($raw, 0, 500),
            ]);

            return $response->toArray(false);
        } catch (\Exception $e) {
            $this->logger?->error('openai.assistants.error', ['error' => $e->getMessage()]);
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
            'instructions' => "Du bist ein digitaler Produktberater in einem Onlineshop. Deine Aufgabe ist es, Kunden bei der Suche nach passenden Produkten zu helfen. Verwende ausschließlich Informationen aus den Produkten, die im Vektor-Index gespeichert wurden. Verwende keine Daten aus dem Internet oder aus anderen Quellen.

Sprich den Kunden freundlich und verständlich an. Verwende keine technischen Begriffe wie „Dateien hochgeladen“, „Vektorstore“ oder „Datenbank“. Antworte so, wie es ein menschlicher Verkaufsberater im Geschäft tun würde. Du bist kein Verkäufer und darfst keine Verträge abschließen – du gibst nur Empfehlungen und Informationen zu Produkten.

Wenn du zu einer Kundenfrage keine passenden Informationen in den gespeicherten Produktdaten findest, sag freundlich, dass dir dazu leider keine passenden Produkte vorliegen. Und gib ihm Informationen zum Kundensupport des Webshops: Philippe-vasco.reers@adesso.de und Tel: 0231/5551234

Der Kunde, der mit dir spricht, ist ein normaler Endkunde. Behandle ihn entsprechend – höflich, hilfsbereit und ohne Fachjargon. Reagiere auf Fragen wie „Was bist du?“ oder „Was kannst du tun?“ so, als seist du ein hilfreicher Berater im Shop, ohne auf technische Details einzugehen.

Nutze File-Search intern, füge keine Zitate, Dateinamen oder Quellenverweise in den Text ein.
"
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
