<?php
declare(strict_types=1);

namespace App\Controller\rag\response;

use App\Service\rag\response\OpenAIResponseAssistantService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/rag/response', name: 'rag_response_assistant_')]
#[Route('/rag/assistant', name: 'rag_assistant_')]
final class AssistantChatController extends AbstractController
{
    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    #[Route('/chat-assistant', name: 'chat', methods: ['GET'])]
    public function chat(Request $request): Response
    {
        $request->getSession()->invalidate();
        return $this->render('rag/response/chat_assistant/index.html.twig');
    }

    #[Route('/chat-assistant/send', name: 'chat_send', methods: ['POST'])]
    public function send(Request $request, OpenAIResponseAssistantService $openai): Response
    {
        $text  = trim((string)$request->request->get('message', ''));
        $image = $request->files->get('image');
        $promptVarsRaw = (string)$request->request->get('prompt_variables', '');
        $promptVars = null;
        if ($promptVarsRaw !== '') {
            try { $decoded = json_decode($promptVarsRaw, true, 512, JSON_THROW_ON_ERROR); if (is_array($decoded)) { $promptVars = $decoded; } } catch (\Throwable) {}
        }

        if ($text === '' && !$image) {
            $this->logger?->error('assistant_chat.invalid_request', ['reason' => 'empty input']);
            return $this->json(['ok' => false, 'error' => 'Leere Anfrage'], 400);
        }

        $session = $request->getSession();

        try {
            $t0 = microtime(true);
            $apiResponse = $openai->createResponse(
                userText: $text,
                image: $image instanceof UploadedFile ? $image : null,
                promptVariables: $promptVars,
                extra: null,
            );
            $durationMs = (int) ((microtime(true) - $t0) * 1000);

            if (isset($apiResponse['error'])) {
                $this->logger?->error('assistant_chat.api_error', ['error' => $apiResponse['error']]);
                return $this->json([
                    'ok' => false,
                    'error' => $apiResponse['error']['message'] ?? 'OpenAI-Fehler',
                    'response' => $apiResponse,
                ], 502);
            }

            $answer = OpenAIResponseService::extractText($apiResponse);
            $this->logger?->info('assistant_chat.ok', ['duration_ms' => $durationMs, 'answer_len' => mb_strlen($answer)]);
            return $this->json([
                'ok'       => true,
                'response' => $apiResponse,
                'answer'   => $answer,
                'stats'    => [ 'duration_ms' => $durationMs ],
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('assistant_chat.exception', ['error' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => 'Interner Fehler: '.$e->getMessage()], 500);
        }
    }
}
