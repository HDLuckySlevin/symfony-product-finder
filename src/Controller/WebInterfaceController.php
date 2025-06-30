<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Console\Application;
use Symfony\Bundle\FrameworkBundle\Console\Application as KernelApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Command\Command;
use App\Command\TestSearchCommand;
use App\Command\ProcessImageCommand;
use App\Command\ProcessAudioCommand;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use App\Service\PythonEmbeddingService;
use App\Service\MilvusVectorStoreService;
use App\Command\ImportProductsCommand;

class WebInterfaceController extends AbstractController
{
    private const MAX_QUERY_LENGTH = 500;
    private const MAX_IMAGE_SIZE = 5242880; // 5 MB
    private const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png'];
    private const MAX_AUDIO_SIZE = 5242880; // 5 MB

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
    }

    #[Route('/search', name: 'app_web_search', methods: ['POST'])]
    public function search(Request $request, TestSearchCommand $command, KernelInterface $kernel): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $query = isset($data['query']) ? trim((string) $data['query']) : '';
        if ($query === '' || mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return new JsonResponse(
                ['success' => false, 'message' => 'Invalid query'],
                400
            );
        }


        $application = new Application();
        $application->setAutoExit(false);
        $command->setApplication($application);

        $input = new ArrayInput([
            'command' => $command->getName(),
            'query' => $query,
        ]);

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        return new JsonResponse([
            'success' => $result === Command::SUCCESS,
            'output' => $output->fetch(),
        ]);
    }

    #[Route('/search/image', name: 'app_web_search_image', methods: ['POST'])]
    public function searchImage(Request $request, ProcessImageCommand $processImageCommand, TestSearchCommand $testSearchCommand, KernelInterface $kernel): JsonResponse
    {
        $file = $request->files->get('image');
        if (!$file) {
            return new JsonResponse(['success' => false, 'message' => 'No image uploaded'], 400);
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_IMAGE_TYPES, true)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid image type'], 400);
        }

        if ($file->getSize() > self::MAX_IMAGE_SIZE) {
            return new JsonResponse(['success' => false, 'message' => 'Image too large'], 400);
        }

        $application = new Application();
        $application->setAutoExit(false);
        $application->add($testSearchCommand);
        $processImageCommand->setApplication($application);

        $imagePath = $file->getPathname();

        $input = new ArrayInput([
            'command' => $processImageCommand->getName(),
            'image' => $imagePath,
        ]);

        $output = new BufferedOutput();
        $result = $processImageCommand->run($input, $output);

        if (is_string($imagePath) && file_exists($imagePath)) {
            @unlink($imagePath);
        }

        return new JsonResponse([
            'success' => $result === Command::SUCCESS,
            'output' => $output->fetch(),
        ]);
    }

    #[Route('/search/audio', name: 'app_web_search_audio', methods: ['POST'])]
    public function searchAudio(Request $request, ProcessAudioCommand $processAudioCommand, TestSearchCommand $testSearchCommand, KernelInterface $kernel): JsonResponse
    {
        $file = $request->files->get('audio');
        if (!$file) {
            $this->logger->error('No audio uploaded');
            return new JsonResponse(['success' => false, 'message' => 'No audio uploaded'], 400);
        }

        $mimeType = $file->getMimeType() ?? '';
        if (!str_starts_with($mimeType, 'audio/') && $mimeType !== 'video/webm') {
            $this->logger->error('Invalid audio type', ['mime' => $mimeType]);
            return new JsonResponse(['success' => false, 'message' => 'Invalid audio type'], 400);
        }

        if ($file->getSize() > self::MAX_AUDIO_SIZE) {
            $this->logger->error('Uploaded audio too large', ['size' => $file->getSize()]);
            return new JsonResponse(['success' => false, 'message' => 'Audio too large'], 400);
        }

        $this->logger->info('Received audio upload', [
            'mime' => $mimeType,
            'size' => $file->getSize(),
        ]);

        $extension = $file->guessExtension() ?: 'webm';
        $filename = uniqid('audio_', true) . '.' . $extension;
        $tempDir = sys_get_temp_dir();
        $file->move($tempDir, $filename);
        $audioPath = $tempDir . '/' . $filename;

        $this->logger->debug('Stored uploaded audio', ['path' => $audioPath]);

        $application = new Application();
        $application->setAutoExit(false);
        $application->add($testSearchCommand);
        $processAudioCommand->setApplication($application);

        $input = new ArrayInput([
            'command' => $processAudioCommand->getName(),
            'audio' => $audioPath,
        ]);

        $output = new BufferedOutput();
        $result = $processAudioCommand->run($input, $output);
        $commandOutput = $output->fetch();

        $this->logger->info('Finished processing audio', [
            'exit_code' => $result,
            'output' => $commandOutput,
        ]);

        if (is_string($audioPath) && file_exists($audioPath)) {
            @unlink($audioPath);
        }

        return new JsonResponse([
            'success' => $result === Command::SUCCESS,
            'output' => $commandOutput,
        ]);
    }

    #[Route('/embedding/active', name: 'app_active_embedding_model', methods: ['GET'])]
    public function activeEmbeddingModel(PythonEmbeddingService $embeddingService): JsonResponse
    {
        $data = $embeddingService->getActiveEmbeddingModel();
        return new JsonResponse($data);
    }

    #[Route('/embedding/models', name: 'app_available_models', methods: ['GET'])]
    public function availableModels(PythonEmbeddingService $embeddingService): JsonResponse
    {
        $data = $embeddingService->getAvailableModels();
        return new JsonResponse($data);
    }

    #[Route('/embedding/change', name: 'app_change_embedding_model', methods: ['POST'])]
    public function changeEmbeddingModel(
        Request $request,
        PythonEmbeddingService $embeddingService,
        MilvusVectorStoreService $vectorStore,
        ImportProductsCommand $importCommand,
        KernelInterface $kernel
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true) ?? [];
        $provider = (string)($payload['embedding_provider'] ?? '');
        $model = (string)($payload['model_name'] ?? '');

        if ($provider === '' || $model === '') {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload'], 400);
        }

        $this->logger->info('Received request to change embedding model', [
            'provider' => $provider,
            'model' => $model,
        ]);

        $data = $embeddingService->changeEmbeddingModel($provider, $model);
        $this->logger->info('Embedding service responded', ['response' => $data]);

        // Update dimension based on the newly selected model
        $dimension = $embeddingService->getVectorDimension();
        $vectorStore->setDimension($dimension);

        // Drop collection and recreate it before re-import
        $this->logger->info('Dropping Milvus collection before re-import');
        $dropResult = $vectorStore->dropCollection();
        $this->logger->info('Milvus dropCollection result', ['success' => $dropResult]);

        $createResult = $vectorStore->createCollection($dimension);
        $this->logger->info('Milvus createCollection result', ['success' => $createResult]);

        $this->logger->info('Starting product re-import after embedding model change');
        $application = new KernelApplication($kernel);
        $application->setAutoExit(false);
        $importCommand->setApplication($application);
        $xmlPath = $kernel->getProjectDir() . '/src/DataFixtures/xml/sample_products.xml';
        $input = new ArrayInput([
            'command' => $importCommand->getName(),
            'xml-file' => $xmlPath,
        ]);
        $output = new BufferedOutput();
        $exitCode = $importCommand->run($input, $output);
        $this->logger->info('Finished product re-import', [
            'exit_code' => $exitCode,
            'import_output' => $output->fetch(),
        ]);

        // Verify that vectors exist after import
        $testVector = $embeddingService->generateQueryEmbedding('test');
        $checkResults = $vectorStore->searchSimilarProducts($testVector, 1);
        $hasVectors = count($checkResults) > 0;
        $this->logger->info('Vector presence check', ['has_vectors' => $hasVectors]);
        $response = array_merge([
            'embedding_provider' => $provider,
            'model_name' => $model,
            'vectors' => $hasVectors,
        ], is_array($data) ? $data : []);

        return new JsonResponse($response);
    }
}

