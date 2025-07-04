<?php

namespace App\Controller;

use App\Service\EmbeddingGeneratorInterface;
use App\Service\JsonImportService;
use App\Service\VectorStoreInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API endpoint for importing a single product as JSON.
 */
class ProductImportController extends AbstractController
{
    private EmbeddingGeneratorInterface $embeddingGenerator;
    private VectorStoreInterface $vectorStoreService;
    private JsonImportService $importService;

    public function __construct(
        EmbeddingGeneratorInterface $embeddingGenerator,
        VectorStoreInterface $vectorStoreService,
        JsonImportService $importService
    ) {
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorStoreService = $vectorStoreService;
        $this->importService = $importService;
    }

    #[Route('/api/products', name: 'api_products_import', methods: ['POST'])]
    public function importProduct(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['message' => 'Invalid JSON payload'], 400);
        }

        try {
            $product = $this->importService->importFromArray($payload);

            // Ensure the collection exists
            $this->vectorStoreService->initializeCollection();

            $chunks = $this->embeddingGenerator->generateProductEmbeddings($product);
            $this->vectorStoreService->insertProductChunks($product, $chunks);

            return new JsonResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 500);
        }
    }
}
