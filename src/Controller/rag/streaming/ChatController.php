<?php
declare(strict_types=1);

namespace App\Controller\rag\streaming;

use App\Service\rag\response\OpenAIResponseService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/rag/streaming', name: 'rag_streaming_')]
final class ChatController extends AbstractController
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?string $defaultPromptId = null,
        private readonly ?string $defaultPromptVersion = null,
    ) {}

    #[Route('/chat', name: 'chat', methods: ['GET'])]
    public function chat(Request $request): Response
    {
        $session = $request->getSession();
        $session->invalidate();

        $prevChat = trim((string)$request->query->get('prevChat', ''));
        if ($prevChat !== '') {
            $session->set('rag_prev_response_id', $prevChat);
        }

        return $this->render('rag/streaming/chat/index.html.twig');
    }

    #[Route('/chat/send-stream', name: 'chat_send_stream', methods: ['POST'])]
    public function sendStream(Request $request, OpenAIResponseService $openai): Response
    {
        $text  = trim((string)$request->request->get('message', ''));
        $image = $request->files->get('image');
        $model = $request->request->get('model');

        $promptId      = trim((string)$request->request->get('prompt_id', ''));
        $promptVarsRaw = (string)$request->request->get('prompt_variables', '');
        $promptVersion = trim((string)$request->request->get('prompt_version', ''));
        $prompt = null;
        if ($promptId !== '') {
            $prompt = ['id' => $promptId];
            if ($promptVarsRaw !== '') {
                try {
                    $vars = json_decode($promptVarsRaw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($vars) && !empty($vars)) {
                        $prompt['variables'] = $vars;
                    }
                } catch (\Throwable $e) {
                    $this->logger?->warning('chat.prompt.variables_invalid_json', [
                        'error' => $e->getMessage(),
                        'input' => mb_substr($promptVarsRaw, 0, 500),
                    ]);
                }
            }
            if ($promptVersion !== '') {
                $prompt['version'] = $promptVersion;
            }
        }
        if (!$prompt && $this->defaultPromptId) {
            $prompt = ['id' => $this->defaultPromptId];
            if ($this->defaultPromptVersion) {
                $prompt['version'] = $this->defaultPromptVersion;
            }
        }

        if ($text === '' && !$image) {
            return $this->json(['ok' => false, 'error' => 'Leere Anfrage'], 400);
        }

        $session = $request->getSession();
        $prevId  = $session->get('rag_prev_response_id');

        $session->save();

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($openai, $text, $image, $model, $prevId, $session, $prompt, $request) {
            $session->start();

            $flush = static function () {
                while (ob_get_level() > 0) { @ob_end_flush(); }
                @flush();
            };

            $sendEvent = static function (string $event, array $data) use ($flush) {
                echo 'event: ' . $event . "\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                $flush();
            };

            $this->logger?->info('send_stream.start', [
                'text_len' => mb_strlen($text),
                'has_image' => $image instanceof UploadedFile,
                'model' => $model,
                'prev_id' => $prevId,
                'has_prompt' => is_array($prompt) && !empty($prompt['id']),
            ]);

            echo ": stream-start\n\n";
            $flush();

            // no ping/meta event

            $tokensQuery = 0; $tokensAnswer = 0; $durationMs = 0; $finalId = null; $insufficient = false;
            $t0 = microtime(true);
            try {
                $openai->createResponseStream(
                    userText: $text,
                    image: $image instanceof UploadedFile ? $image : null,
                    model: $model,
                    prompt: $prompt,
                    previousResponseId: is_string($prevId) ? $prevId : null,
                    extra: null,
                    onEvent: function (?string $eventName, array $data) use (&$tokensQuery, &$tokensAnswer, &$durationMs, &$finalId, $sendEvent, $session, $t0, &$insufficient) {
                        switch ($eventName) {
                            case 'response.output_text.delta': {
                                $delta = (string)($data['delta'] ?? '');
                                if ($delta !== '') {
                                    $sendEvent('delta', ['text' => $delta]);
                                }
                                break;
                            }
                            case 'response.error':
                                $msg  = (string)($data['error']['message'] ?? 'Fehler');
                                $sendEvent('error', ['message' => $msg]);
                                break;
                            case 'response.completed':
                                $durationMs = (int)((microtime(true) - $t0) * 1000);
                                $resp = $data['response'] ?? [];
                                if (isset($resp['id'])) { $finalId = (string)$resp['id']; }
                                $usage = $resp['usage'] ?? [];
                                if (isset($usage['total_tokens'])) { $tokensQuery = (int)$usage['total_tokens']; }
                                if (isset($usage['output_tokens'])) { $tokensAnswer = (int)$usage['output_tokens']; }
                                if (isset($usage['completion_tokens'])) { $tokensAnswer = (int)$usage['completion_tokens']; }
                                $tokensSession = (int) $session->get('rag_tokens_total', 0) + (int)$tokensQuery;
                                $session->set('rag_tokens_total', $tokensSession);
                                if ($finalId) { $session->set('rag_prev_response_id', $finalId); }
                
                                $sendEvent('done', [
                                    'id' => $finalId,
                                    'stats' => [
                                        'tokens_query' => $tokensQuery,
                                        'tokens_answer' => $tokensAnswer,
                                        'tokens_session' => (int)$session->get('rag_tokens_total', 0),
                                        'duration_ms' => $durationMs,
                                    ]
                                ]);
                                $session->save();
                                break;
                            case 'response.incomplete':
                                $durationMs = (int)((microtime(true) - $t0) * 1000);
                                $resp = $data['response'] ?? [];
                                $reason = (string)($resp['incomplete_details']['reason'] ?? 'unknown');
                                if (isset($resp['id'])) { $finalId = (string)$resp['id']; }
                                $usage = $resp['usage'] ?? [];
                                if (isset($usage['total_tokens'])) { $tokensQuery = (int)$usage['total_tokens']; }
                                if (isset($usage['output_tokens'])) { $tokensAnswer = (int)$usage['output_tokens']; }
                                if (isset($usage['completion_tokens'])) { $tokensAnswer = (int)$usage['completion_tokens']; }
                                $tokensSession = (int) $session->get('rag_tokens_total', 0) + (int)$tokensQuery;
                                $session->set('rag_tokens_total', $tokensSession);
                                if ($finalId) { $session->set('rag_prev_response_id', $finalId); }
                                $sendEvent('done', [
                                    'id' => $finalId,
                                    'incomplete' => true,
                                    'reason' => $reason,
                                    'stats' => [
                                        'tokens_query' => $tokensQuery,
                                        'tokens_answer' => $tokensAnswer,
                                        'tokens_session' => (int)$session->get('rag_tokens_total', 0),
                                        'duration_ms' => $durationMs,
                                    ]
                                ]);
                                $session->save();
                                break;
                            default:
                                break;
                        }
                    }
                );
            } catch (\Throwable $e) {
                $sendEvent('error', ['message' => $e->getMessage()]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Proxy-Buffering', 'off');
        $response->headers->set('X-Fastcgi-Buffering', 'off');
        return $response;
    }

    // ping removed as requested
}
