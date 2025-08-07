<?php

namespace App\Controller\rag;

use App\Service\rag\RagOpenAiService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

/**
 * @Route("/api/rag")
 */
class RagApiController extends AbstractController
{
    private RagOpenAiService $openAiService;
    private LoggerInterface $logger;

    public function __construct(RagOpenAiService $openAiService, LoggerInterface $logger)
    {
        $this->openAiService = $openAiService;
        $this->logger = $logger;
    }

    #[Route('/api/rag/start', name: 'rag_api_start', methods: ['POST'])]
    public function start(): JsonResponse
    {
        $threadId = $this->openAiService->createThread();

        $this->logger->info('Neuer Thread per API erstellt', [
            'thread_id' => $threadId
        ]);

        return new JsonResponse(['thread_id' => $threadId]);
    }

    #[Route('/api/rag/message', name: 'rag_api_message', methods: ['POST'])]
    public function message(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['message'])) {
                return new JsonResponse(['error' => 'message fehlt'], 400);
            }

            $message = $data['message'];
            $threadId = $data['thread_id'] ?? $this->openAiService->createThread();

            $this->logger->info('API-Message empfangen', [
                'message' => $message,
                'thread_id' => $threadId
            ]);

            // Message senden
            $this->openAiService->sendMessage($threadId, $message);
            $runId = $this->openAiService->startRun($threadId);

            // Auf Antwort warten (bis 30 Sek.)
            $maxWaitSeconds = 30;
            $waited = 0;
            $status = null;

            do {
                sleep(1);
                $status = $this->openAiService->getRunStatus($threadId, $runId);
                $waited++;
            } while ($status !== 'completed' && $waited < $maxWaitSeconds);

            if ($status !== 'completed') {
                return new JsonResponse([
                    'thread_id' => $threadId,
                    'error' => 'Antwort konnte nicht rechtzeitig generiert werden.'
                ], 504);
            }

            // Antwort holen
            $messages = $this->openAiService->getMessages($threadId);

            usort($messages, fn($a, $b) => $b['created_at'] <=> $a['created_at']);

            $assistantMessage = null;
            foreach ($messages as $msg) {
                if ($msg['role'] === 'assistant') {
                    $assistantMessage = $msg['content'][0]['text']['value'] ?? null;
                    break;
                }
            }

            // Annotationen entfernen
            $assistantMessage = preg_replace('/【\d+:\d+†[^】]+】/', '', $assistantMessage);

            return new JsonResponse([
                'thread_id' => $threadId,
                'answer' => $assistantMessage
            ]);

        } catch (NotEncodableValueException | \JsonException $e) {
            return new JsonResponse(['error' => 'Ungültiges JSON'], 400);
        } catch (\Throwable $t) {
            $this->logger->error('Fehler in rag_api_message', [
                'error' => $t->getMessage()
            ]);
            return new JsonResponse(['error' => 'Interner Fehler'], 500);
        }
    }

    #[Route('/api/rag/reset', name: 'rag_api_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['thread_id'])) {
                return new JsonResponse(['error' => 'thread_id fehlt'], 400);
            }

            $threadId = $data['thread_id'];
            $this->openAiService->deleteThread($threadId);

            $this->logger->info('Thread gelöscht (API)', [
                'thread_id' => $threadId
            ]);

            return new JsonResponse(['status' => 'reset', 'thread_id' => $threadId]);

        } catch (\Throwable $t) {
            return new JsonResponse(['error' => $t->getMessage()], 500);
        }
    }
}
