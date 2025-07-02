<?php

namespace App\Controller;

use App\Service\OpenAIEmbeddingService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class EmbeddingController extends AbstractController
{
    private OpenAIEmbeddingService $service;
    private LoggerInterface $logger;

    public function __construct(OpenAIEmbeddingService $service, LoggerInterface $logger)
    {
        $this->service = $service;
        $this->logger = $logger;
    }

    #[Route('/text-embedding', methods: ['POST'])]
    public function textEmbedding(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $texts = array_map('strval', (array)($payload['texts'] ?? []));
        if ($texts === [] || $texts === [""]) {
            return new JsonResponse(['error' => 'No texts provided'], 400);
        }
        try {
            $vectors = $this->service->createTextEmbeddings($texts);
            return new JsonResponse(['vectors' => $vectors]);
        } catch (\Throwable $e) {
            $this->logger->error('Text embedding failed', ['exception' => $e]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/image-embedding', methods: ['POST'])]
    public function imageEmbedding(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['error' => 'Missing file'], 400);
        }
        try {
            $result = $this->service->describeImageFile($file);
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Image embedding failed', ['exception' => $e]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/activeembeddingmodell', methods: ['GET'])]
    public function activeEmbeddingModel(): JsonResponse
    {
        return new JsonResponse($this->service->getActiveEmbeddingModel());
    }

    #[Route('/dimension', methods: ['GET'])]
    public function dimension(): JsonResponse
    {
        return new JsonResponse(['dimension' => $this->service->getVectorDimension()]);
    }

    #[Route('/healthstatus', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse($this->service->healthStatus());
    }
}
