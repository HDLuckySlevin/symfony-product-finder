<?php
declare(strict_types=1);

namespace App\Controller\rag\response;

use App\Service\rag\response\OpenAIResponseService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/rag/response', name: 'rag_response_')]
final class ChatController extends AbstractController
{
    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    #[Route('/chat', name: 'chat', methods: ['GET'])]
    public function chat(): Response
    {
        return $this->render('rag/response/chat/index.html.twig');
    }

    // src/Controller/rag/response/ChatController.php
    #[Route('/chat/send', name: 'chat_send', methods: ['POST'])]
    public function send(Request $request, OpenAIResponseService $openai): Response
    {
        $text  = trim((string)$request->request->get('message', ''));
        $image = $request->files->get('image');
        $model = $request->request->get('model');

        if ($text === '' && !$image) {
            return $this->json(['ok' => false, 'error' => 'Leere Anfrage'], 400);
        }

        $session = $request->getSession();
        $prevId  = $session->get('rag_prev_response_id'); // ← zuletzt gemerkte ID

        try {
            // Measure upstream ping to OpenAI (cached per session for 5s)
            $pingMs = $this->getCachedPing($request, $openai);
            $t0 = microtime(true);
            $apiResponse = $openai->createResponse(
                userText: $text,
                image: $image,
                model: $model,
                extra: null,
                previousResponseId: $prevId // ← hier rein
            );
            $durationMs = (int) ((microtime(true) - $t0) * 1000);

            // OpenAI‑Fehler sauber durchreichen
            if (isset($apiResponse['error'])) {
                $err = (array) $apiResponse['error'];
                $type = (string) ($err['type'] ?? '');
                $code = (string) ($err['code'] ?? '');
                $msg  = (string) ($err['message'] ?? '');
                $insufficient = stripos($type, 'insufficient_quota') !== false
                    || stripos($code, 'insufficient_quota') !== false
                    || (stripos($msg, 'insufficient') !== false && stripos($msg, 'quota') !== false)
                    || (stripos($msg, 'budget') !== false && stripos($msg, 'exceeded') !== false);

                return $this->json([
                    'ok' => false,
                    'error' => $apiResponse['error']['message'] ?? 'OpenAI-Fehler',
                    'response' => $apiResponse,
                    'insufficient_quota' => $insufficient,
                ], 502);
            }

            // NEU: aktuelle response.id speichern
            if (!empty($apiResponse['id'])) {
                $session->set('rag_prev_response_id', $apiResponse['id']);
            }

            $answer = OpenAIResponseService::extractText($apiResponse);

            // Token Usage ermitteln (Responses API kann unterschiedliche Felder liefern)
            $tokensQuery = 0;
            $tokensAnswer = 0;
            $usage = $apiResponse['usage'] ?? [];
            if (is_array($usage)) {
                if (isset($usage['total_tokens']) && is_numeric($usage['total_tokens'])) {
                    $tokensQuery = (int) $usage['total_tokens'];
                } elseif (isset($usage['input_tokens'], $usage['output_tokens'])) {
                    $tokensQuery = (int) $usage['input_tokens'] + (int) $usage['output_tokens'];
                    $tokensAnswer = (int) $usage['output_tokens'];
                } elseif (isset($usage['prompt_tokens'], $usage['completion_tokens'])) {
                    $tokensQuery = (int) $usage['prompt_tokens'] + (int) $usage['completion_tokens'];
                    $tokensAnswer = (int) $usage['completion_tokens'];
                }
            }

            $tokensSession = (int) $session->get('rag_tokens_total', 0) + $tokensQuery;
            $session->set('rag_tokens_total', $tokensSession);

            return $this->json([
                'ok'       => true,
                'response' => $apiResponse,
                'answer'   => $answer,
                'stats'    => [
                    'tokens_query'    => $tokensQuery,
                    'tokens_answer'   => $tokensAnswer,
                    'tokens_session'  => $tokensSession,
                    'duration_ms'     => $durationMs,
                    'ping_ms'         => $pingMs,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'Interner Fehler: '.$e->getMessage()], 500);
        }
    }

    #[Route('/chat/reset', name: 'chat_reset', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        $s = $request->getSession();
        $s->remove('rag_prev_response_id');   // ← Konversation zurücksetzen
        $s->remove('rag_history');            // falls du zusätzlich Text‑History nutzt
        $s->remove('rag_tokens_total');       // Token-Zähler für Sitzung zurücksetzen
        return $this->json(['ok' => true]);
    }


    #[Route('/response/{id}', name: 'get', methods: ['GET'])]
    public function getOne(string $id, OpenAIResponseService $openai): Response
    {
        try {
            $apiResponse = $openai->getResponse($id);
            $answer      = OpenAIResponseService::extractText($apiResponse);
            $this->logger?->info('chat.get.ok', ['id' => $id]);

            return $this->json([
                'ok'       => true,
                'response' => $apiResponse,
                'answer'   => $answer,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('chat.get.fail', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => 'Interner Fehler: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/response/{id}', name: 'delete', methods: ['DELETE'])]
    public function deleteOne(string $id, OpenAIResponseService $openai): Response
    {
        try {
            $result = $openai->deleteResponse($id);
            $this->logger?->info('chat.delete.ok', ['id' => $id, 'result' => $result['deleted'] ?? null]);
            return $this->json(['ok' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            $this->logger?->error('chat.delete.fail', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => 'Interner Fehler: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/ping', name: 'ping', methods: ['GET'])]
    public function ping(Request $request, OpenAIResponseService $openai): Response
    {
        try {
            $pingMs = $this->getCachedPing($request, $openai);
            return $this->json(['ok' => true, 'ping_ms' => $pingMs]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function getCachedPing(Request $request, OpenAIResponseService $openai): int
    {
        $session = $request->getSession();
        $lastTs = (int) ($session->get('rag_ping_last_ts') ?? 0);
        $lastMs = (int) ($session->get('rag_ping_last_ms') ?? -1);
        if ($lastTs > 0 && (time() - $lastTs) < 5 && $lastMs !== 0) {
            return $lastMs;
        }
        $ms = $openai->ping();
        $session->set('rag_ping_last_ts', time());
        $session->set('rag_ping_last_ms', $ms);
        return $ms;
    }


}
