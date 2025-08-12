<?php
declare(strict_types=1);

namespace App\Controller\rag\response;

use App\Service\rag\response\OpenAIResponseBasicService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/rag/response', name: 'rag_response_')]
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
        $request->getSession()->invalidate();
        return $this->render('rag/response/chat/index.html.twig');
    }

    #[Route('/chat/send', name: 'chat_send', methods: ['POST'])]
    public function send(Request $request, OpenAIResponseBasicService $openai): Response
    {
        $text  = trim((string)$request->request->get('message', ''));
        $image = $request->files->get('image');
        $model = $request->request->get('model');

        // Prompt-Parameter (optional)
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
        // Apply default prompt from env if none provided
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

        try {
            $t0 = microtime(true);
            $apiResponse = $openai->createResponse(
                userText: $text,
                image: $image instanceof UploadedFile ? $image : null,
                model: $model,
                prompt: $prompt,
                previousResponseId: null,
                extra: null,
            );
            $durationMs = (int) ((microtime(true) - $t0) * 1000);

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

            // no session id tracking in basic non-stream

            $answer = OpenAIResponseBasicService::extractText($apiResponse);
            // Für den Gesamttext ist "sanftes" Aufräumen ok:
            //$answer = $this->stripInlineCitationsBlock($answer);

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
                    'ping_ms'         => -1,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'Interner Fehler: '.$e->getMessage()], 500);
        }
    }

    // Streaming moved to /rag/streaming; legacy route disabled
    #[Route('/__disabled_send_stream', name: 'chat_send_stream_legacy', methods: ['POST'])]
    public function sendStream(Request $request, OpenAIResponseService $openai): Response
    {
        $text  = trim((string)$request->request->get('message', ''));
        $image = $request->files->get('image');
        $model = $request->request->get('model');

        // Prompt-Parameter (optional)
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
        // Apply default prompt from env if none provided
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
        $pingMs  = $this->getCachedPing($request, $openai);

        // Release session lock before starting long-running stream
        $session->save();

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($openai, $text, $image, $model, $prevId, $session, $pingMs, $prompt, $request) {
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

            // Send initial padding to kick off streaming through proxies
            echo ": stream-start\n\n";
            $flush();

            $sendEvent('meta', ['ping_ms' => $pingMs]);

            $tokensQuery = 0; $tokensAnswer = 0; $durationMs = 0; $finalId = null; $insufficient = false;
            $t0 = microtime(true);
            try {
                // Optional generation controls from request (streaming path)
                $extra = [];
                $mot = $request->request->get('max_output_tokens');
                if (is_numeric($mot)) { $extra['max_output_tokens'] = max(1, (int)$mot); }
                $temp = $request->request->get('temperature');
                if (is_numeric($temp)) { $extra['temperature'] = (float)$temp; }
                $openai->createResponseStream(
                    userText: $text,
                    image: $image instanceof UploadedFile ? $image : null,
                    model: $model,
                    prompt: $prompt,
                    previousResponseId: is_string($prevId) ? $prevId : null,
                    extra: $extra ?: null,
                    onEvent: function (?string $eventName, array $data) use (&$tokensQuery, &$tokensAnswer, &$durationMs, &$finalId, $sendEvent, $session, $t0, &$insufficient) {
                        switch ($eventName) {
                            case 'response.output_text.delta': {
                                $delta = (string)($data['delta'] ?? '');
                                if ($delta !== '') {
                                    // **WICHTIG**: Im Streaming **keine** Trims/Whitespace-Zusammenfassung!
                                    $delta = $this->stripInlineCitationsDelta($delta);
                                    if ($delta !== '') {
                                        $this->logger?->debug('send_stream.delta', ['len' => mb_strlen($delta), 'preview' => mb_substr($delta, 0, 120)]);
                                        $sendEvent('delta', ['text' => $delta]);
                                    }
                                }
                                break;
                            }

                            case 'response.output_text.done':
                                $this->logger?->debug('send_stream.output_done');
                                break;

                            case 'response.refusal.delta': {
                                $delta = (string)($data['delta'] ?? '');
                                if ($delta !== '') {
                                    $delta = $this->stripInlineCitationsDelta($delta);
                                    if ($delta !== '') {
                                        $this->logger?->debug('send_stream.refusal_delta', ['len' => mb_strlen($delta), 'preview' => mb_substr($delta, 0, 120)]);
                                        $sendEvent('delta', ['text' => $delta]);
                                    }
                                }
                                break;
                            }

                            case 'response.tool_call.delta':
                                $this->logger?->debug('send_stream.tool_delta', ['data' => $data]);
                                $sendEvent('tool_delta', $data);
                                break;

                            case 'response.tool_call.done':
                                $this->logger?->debug('send_stream.tool_done', ['data' => $data]);
                                $sendEvent('tool_done', $data);
                                break;

                            // Optional: File-Search-Phasen an den Client weiterreichen
                            case 'response.file_search_call.in_progress':
                            case 'response.file_search_call.searching':
                            case 'response.file_search_call.completed':
                                $this->logger?->debug('send_stream.file_search', ['event' => $eventName, 'data' => $data]);
                                $sendEvent('file_search', ['event' => $eventName, 'data' => $data]);
                                break;

                            case 'response.error':
                                $msg  = (string)($data['error']['message'] ?? 'Fehler');
                                $type = (string)($data['error']['type'] ?? '');
                                $code = (string)($data['error']['code'] ?? '');
                                $insufficient = stripos($type, 'insufficient_quota') !== false
                                    || stripos($code, 'insufficient_quota') !== false
                                    || (stripos($msg, 'insufficient') !== false && stripos($msg, 'quota') !== false)
                                    || (stripos($msg, 'budget') !== false && stripos($msg, 'exceeded') !== false);
                                $this->logger?->error('send_stream.error', ['message' => $msg, 'type' => $type, 'code' => $code, 'insufficient_quota' => $insufficient]);
                                $sendEvent('error', ['message' => $msg, 'insufficient_quota' => $insufficient]);
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
                                $this->logger?->info('send_stream.completed', [
                                    'response_id' => $finalId,
                                    'tokens_query' => $tokensQuery,
                                    'tokens_answer' => $tokensAnswer,
                                    'tokens_session' => (int)$session->get('rag_tokens_total', 0),
                                    'duration_ms' => $durationMs,
                                ]);
                                $sendEvent('done', [
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
                                $this->logger?->warning('send_stream.incomplete', [
                                    'response_id' => $finalId,
                                    'reason' => $reason,
                                    'tokens_query' => $tokensQuery,
                                    'tokens_answer' => $tokensAnswer,
                                    'duration_ms' => $durationMs,
                                ]);
                                $sendEvent('done', [
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
                                $this->logger?->debug('send_stream.event_ignored', [
                                    'event' => $eventName,
                                    'data_preview' => mb_substr(json_encode($data), 0, 200)
                                ]);
                                break;
                        }
                    }
                );
            } catch (\Throwable $e) {
                $this->logger?->error('send_stream.exception', ['error' => $e->getMessage()]);
                $sendEvent('error', ['message' => $e->getMessage(), 'insufficient_quota' => $insufficient ?? false]);
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

    #[Route('/response/{id}', name: 'get', methods: ['GET'])]
    public function getOne(string $id): Response
    {
        try {
            return $this->json(['ok' => false, 'error' => 'Not supported'], 400);
            // Für den gesamten Text ok:
            $answer      = $this->stripInlineCitationsBlock($answer);
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
    public function deleteOne(string $id): Response
    {
        try {
            return $this->json(['ok' => false, 'error' => 'Not supported in basic version'], 400);
        } catch (\Throwable $e) {
            $this->logger?->error('chat.delete.fail', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->json(['ok' => false, 'error' => 'Interner Fehler: ' . $e->getMessage()], 500);
        }
    }

    // no ping route in non-stream version

    #[Route('/chat/reset', name: 'chat_reset', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        $s = $request->getSession();
        $s->remove('rag_prev_response_id');
        $s->remove('rag_history');
        $s->remove('rag_tokens_total');
        $s->remove('rag_ping_last_ts');
        $s->remove('rag_ping_last_ms');
        $this->logger?->info('send_stream.reset');
        return $this->json(['ok' => true]);
    }

    // no cached ping helper in non-stream version

    /**
     * Entfernt Inline-Citations (Private Use Area) **ohne** Spaces/Zeilen zu verändern.
     * Für Streaming-Deltas: KEIN trim(), KEINE Space-Kosmetik.
     */
    private function stripInlineCitationsDelta(string $text): string
    {
        // Matches: U+E200 ""
        // Beispiel:   oder
        $text = preg_replace('/\x{E200}(?:cite|filecite)\x{E202}.*?\x{E201}/u', '', $text) ?? $text;
        return $text; // NICHT trimmen!
    }

    /**
     * Entfernt Inline-Citations und räumt den **Gesamttext** sanft auf.
     * Für Nicht-Streaming/Finaltext geeignet.
     */
    private function stripInlineCitationsBlock(string $text): string
    {
        $text = preg_replace('/\x{E200}(?:cite|filecite)\x{E202}.*?\x{E201}/u', '', $text) ?? $text;
        // nur sanftes Aufräumen, keine aggressiven Kürzungen
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}
