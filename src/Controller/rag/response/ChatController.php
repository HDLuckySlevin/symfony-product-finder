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
            $apiResponse = $openai->createResponse(
                userText: $text,
                image: $image,
                model: $model,
                extra: null,
                previousResponseId: $prevId // ← hier rein
            );

            // OpenAI‑Fehler sauber durchreichen
            if (isset($apiResponse['error'])) {
                return $this->json([
                    'ok' => false,
                    'error' => $apiResponse['error']['message'] ?? 'OpenAI-Fehler',
                    'response' => $apiResponse,
                ], 502);
            }

            // NEU: aktuelle response.id speichern
            if (!empty($apiResponse['id'])) {
                $session->set('rag_prev_response_id', $apiResponse['id']);
            }

            $answer = OpenAIResponseService::extractText($apiResponse);

            return $this->json([
                'ok'       => true,
                'response' => $apiResponse,
                'answer'   => $answer,
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

}
