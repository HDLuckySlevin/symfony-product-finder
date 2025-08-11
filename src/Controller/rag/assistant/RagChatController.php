<?php

namespace App\Controller\rag\assistant;

use App\Service\rag\assistant\RagOpenAiService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class RagChatController extends AbstractController
{
    private RagOpenAiService $openAiService;
    private LoggerInterface $logger;

    public function __construct(RagOpenAiService $openAiService, #[Autowire(service: 'monolog.logger.rag')] LoggerInterface $logger)
    {
        $this->openAiService = $openAiService;
        $this->logger = $logger;
    }

    /**
     * Lädt die Chat-Oberfläche.
     * Falls noch kein Thread existiert, wird ein neuer Thread bei OpenAI erstellt
     * und dessen ID in der Session gespeichert.
     *
     * @param SessionInterface $session
     * @return Response HTML-Response mit der Chat-Oberfläche
     */
    #[Route('/rag/assistant/chat', name: 'rag_chat_index', methods: ['GET'])]
    public function index(SessionInterface $session): Response
    {
        $this->logger->info('🧪 Testeintrag für rag.log');
        if (!$session->has('rag_thread_id')) {
            $threadId = $this->openAiService->createThread();
            $session->set('rag_thread_id', $threadId);
            $this->logger->info('Neuer Thread erstellt', ['thread_id' => $threadId]);
        }

        return $this->render('rag/assistant/chat/index.html.twig');
    }

    /**
     * Verarbeitet eine vom Benutzer gesendete Nachricht.
     * Die Nachricht wird an OpenAI gesendet, ein Run wird gestartet,
     * der Status gepollt und die Antwort anschließend zurückgegeben.
     *
     * @param Request $request
     * @param SessionInterface $session
     * @return JsonResponse JSON-Antwort mit dem Text des Assistenten oder Fehlermeldung
     */
    #[Route('/rag/assistant/chat/send', name: 'rag_chat_send', methods: ['POST'])]
    public function send(Request $request, SessionInterface $session): JsonResponse
    {
        $threadId = $session->get('rag_thread_id');
        $userMessage = $request->request->get('message');

        if (!$threadId || !$userMessage) {
            return new JsonResponse(['error' => 'Ungültige Anfrage.'], 400);
        }

        try {
            $this->logger->info('User message received', [
                'thread_id' => $threadId,
                'message' => $userMessage,
            ]);

            // 1. Nachricht senden
            $this->openAiService->sendMessage($threadId, $userMessage);

            // 2. Run starten
            $runId = $this->openAiService->startRun($threadId);
            $this->logger->info('Run gestartet', [
                'thread_id' => $threadId,
                'run_id' => $runId,
            ]);

            // 3. Run-Status pollen (bis zu 30 Sekunden)
            $maxWaitSeconds = 30;
            $waited = 0;
            $status = null;

            do {
                sleep(1);
                $status = $this->openAiService->getRunStatus($threadId, $runId);
                $waited++;
            } while ($status !== 'completed' && $waited < $maxWaitSeconds);

            if ($status !== 'completed') {
                $this->logger->warning('Run nicht abgeschlossen nach Timeout', [
                    'thread_id' => $threadId,
                    'run_id' => $runId,
                    'status' => $status
                ]);

                return new JsonResponse(['error' => 'Antwort konnte nicht rechtzeitig generiert werden.'], 504);
            }

            $this->logger->info('Run abgeschlossen', [
                'thread_id' => $threadId,
                'run_id' => $runId,
            ]);

            // 4. Antwort abrufen
            $messages = $this->openAiService->getMessages($threadId);

            // Nachrichten nach Zeitstempel sortieren und letzte assistant-Antwort extrahieren
            usort($messages, fn($a, $b) => $b['created_at'] <=> $a['created_at']);

            $assistantMessage = null;
            foreach ($messages as $message) {
                if ($message['role'] === 'assistant') {
                    $assistantMessage = $message['content'][0]['text']['value'] ?? null;
                    break;
                }
            }

            if (!$assistantMessage) {
                return new JsonResponse(['error' => 'Keine Antwort vom Assistenten erhalten.'], 500);
            }

            // Annotation-Links bereinigen (z. B.  )
            $assistantMessage = preg_replace('/【\d+:\d+†[^】]+】/', '', $assistantMessage);

            $this->logger->info('Assistentenantwort erhalten', [
                'thread_id' => $threadId,
                'response' => $assistantMessage,
            ]);

            return new JsonResponse([
                'answer' => $assistantMessage,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Fehler im Chatprozess', [
                'exception' => $e->getMessage(),
                'thread_id' => $threadId ?? 'N/A'
            ]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Löscht den aktuell aktiven Thread bei OpenAI und entfernt ihn aus der Session.
     * Wird z. B. beim Zurücksetzen der Konversation verwendet.
     *
     * @param SessionInterface $session
     * @return JsonResponse JSON-Antwort mit Bestätigung der Löschung
     */
    #[Route('/rag/assistant/chat/reset', name: 'rag_chat_reset', methods: ['POST'])]
    public function reset(SessionInterface $session): JsonResponse
    {
        $threadId = $session->get('rag_thread_id');

        if ($threadId) {
            try {
                $this->openAiService->deleteThread($threadId);
                $this->logger->info('Thread gelöscht', ['thread_id' => $threadId]);
            } catch (\Throwable $t) {
                $this->logger->warning('Fehler beim Löschen des Threads', [
                    'thread_id' => $threadId,
                    'error' => $t->getMessage(),
                ]);
            }
        }

        $session->remove('rag_thread_id');

        return new JsonResponse(['status' => 'reset']);
    }
}
