<?php

declare(strict_types=1);

namespace App\Controller\rag\assistant;

use App\Service\rag\assistant\RagOpenAiService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\Attribute\Autowire;

final class ImageUploadController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RagOpenAiService $ragService,
        #[Autowire(service: 'monolog.logger.rag')] private readonly LoggerInterface $logger
    ) {}

    #[Route('/rag/upload-image', name: 'rag_upload_image', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $image */
        $image = $request->files->get('image');
        if (!$image || !$image->isValid()) {
            return new JsonResponse(['error' => 'Kein gültiges Bild hochgeladen'], 400);
        }

        // ✅ Extension/Mime VOR dem move() bestimmen
        $ext = $image->guessExtension() ?: $image->getClientOriginalExtension() ?: 'png';

        $tmpPath = sys_get_temp_dir() . '/' . uniqid('upload_', true) . '.' . $ext;
        $fs = new Filesystem();
        try {
            $image->move(\dirname($tmpPath), \basename($tmpPath));
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Upload fehlgeschlagen'], 500);
        }

        $this->logger->info('Bild empfangen', ['path' => $tmpPath, 'size' => @filesize($tmpPath)]);

        try {
            // ✅ Ab hier NUR noch mit $tmpPath arbeiten (altes Tmp-File existiert nicht mehr)
            $mimeExt = pathinfo($tmpPath, PATHINFO_EXTENSION) ?: 'png';
            $base64  = base64_encode(file_get_contents($tmpPath));
            $dataUrl = sprintf('data:image/%s;base64,%s', $mimeExt, $base64);

            // Vision-Analyse
            $visionResp = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . ($_ENV['OPENAI_API_KEY'] ?? ''),
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => 'gpt-4o', // ggf. auf 'gpt-5' anheben, wenn verfügbar
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Beschreibe präzise, was auf dem Bild zu sehen ist (Marke/Modell falls erkennbar, Kamera-Anzahl, Materialien, Besonderheiten). Formuliere so, dass ein Produkt-RAG passende Elektronikprodukte zuordnen kann.',
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => $dataUrl],
                            ],
                        ],
                    ]],
                    'max_tokens' => 400,
                ],
                'timeout' => 60,
            ]);

            $visionData  = $visionResp->toArray(false);
            $description = $visionData['choices'][0]['message']['content'] ?? null;
            if (!$description) {
                $this->logger->warning('Vision-Antwort ohne content', ['raw' => $visionData]);
                return new JsonResponse(['error' => 'Vision-Analyse fehlgeschlagen'], 502);
            }

            // Thread aus Session oder neu
            $session  = $request->getSession();
            $threadId = $session->get('rag_thread_id') ?? $this->ragService->createThread();
            $session->set('rag_thread_id', $threadId);

            // Beschreibung an Assistant
            $this->ragService->sendMessage($threadId, $description);
            $runId = $this->ragService->startRun($threadId);

            // Polling max. 30s
            $status = null;
            for ($i = 0; $i < 30; $i++) {
                sleep(1);
                $status = $this->ragService->getRunStatus($threadId, $runId);
                if ($status === 'completed') break;
            }
            if ($status !== 'completed') {
                return new JsonResponse([
                    'thread_id' => $threadId,
                    'prompt'    => $description,
                    'error'     => 'Antwort konnte nicht rechtzeitig generiert werden.',
                ], 504);
            }

            // Antwort holen
            $messages = $this->ragService->getMessages($threadId);
            \usort($messages, fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
            $answer = null;
            foreach ($messages as $msg) {
                if (($msg['role'] ?? '') === 'assistant') {
                    $answer = $msg['content'][0]['text']['value'] ?? null;
                    break;
                }
            }
            if ($answer !== null) {
                // Annotationen wie  entfernen
                $answer = (string)\preg_replace('/【\d+:\d+†[^】]+】/', '', $answer);
            }

            return new JsonResponse([
                'thread_id' => $threadId,
                'prompt'    => $description, // Frontend zeigt einklappbar „VisionAI-Analyse“
                'answer'    => $answer,
            ]);
        } catch (\Throwable $t) {
            $this->logger->error('Fehler bei Bildanalyse', ['error' => $t->getMessage()]);
            return new JsonResponse(['error' => 'Interner Fehler bei der Bildanalyse'], 500);
        } finally {
            if ($fs->exists($tmpPath)) {
                $fs->remove($tmpPath);
            }
        }
    }
}
