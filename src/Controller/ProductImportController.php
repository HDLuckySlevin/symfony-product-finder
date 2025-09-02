<?php

namespace App\Controller;

use App\Service\EmbeddingGeneratorInterface;
use App\Service\JsonImportService;
use App\Service\VectorStoreInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
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
    private HttpClientInterface $httpClient;

    public function __construct(
        EmbeddingGeneratorInterface $embeddingGenerator,
        VectorStoreInterface $vectorStoreService,
        JsonImportService $importService,
        HttpClientInterface $httpClient
    ) {
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorStoreService = $vectorStoreService;
        $this->importService = $importService;
        $this->httpClient = $httpClient;
    }

    #[Route('/api/products', name: 'api_products_import', methods: ['POST'])]
    /**
     * Upload a single product definition as JSON and store its vectors.
     *
     * Example request body:
     * ```json
     * {
     *   "id": 2,
     *   "name": "Example Phone",
     *   "sku": "XYZ-12345"
     * }
     * ```
     *
     * Returns `{ "success": true }` on success.
     */
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
            if ($product->getId() !== null) {
                $this->vectorStoreService->deleteProductVectors($product->getId());
            }
            $this->vectorStoreService->insertProductChunks($product, $chunks);

            // Also upload the original JSON to OpenAI Vector Store as `{sku}.json`
            $sku = $payload['sku'] ?? null;
            if (!is_string($sku) || $sku === '') {
                throw new \RuntimeException('Missing or invalid "sku" for vector store file name.');
            }

            $apiKey = (string)($_ENV['OPENAI_API_KEY'] ?? '');
            if ($apiKey === '') {
                throw new \RuntimeException('OPENAI_API_KEY is not configured.');
            }
            $apiBase = rtrim((string)($_ENV['OPENAI_API_BASE'] ?? 'https://api.openai.com/v1'), '/');
            $vectorStoreIdsCsv = (string)($_ENV['OPENAI_VECTOR_STORE_IDS'] ?? '');
            $vectorStoreIds = array_values(array_filter(array_map('trim', explode(',', $vectorStoreIdsCsv))));
            if (empty($vectorStoreIds)) {
                throw new \RuntimeException('OPENAI_VECTOR_STORE_IDS is not configured.');
            }

            // Prepare JSON file content and upload to /files
            $filename = $sku . '.json';
            $jsonContent = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonContent === false) {
                throw new \RuntimeException('Failed to encode JSON for upload.');
            }

            $form = new FormDataPart([
                'purpose' => 'assistants',
                'file' => new DataPart($jsonContent, $filename, 'application/json'),
            ]);
            $headers = $form->getPreparedHeaders()->toArray();
            $headers['Authorization'] = 'Bearer ' . $apiKey;

            $uploadRes = $this->httpClient->request('POST', $apiBase . '/files', [
                'headers' => $headers,
                'body' => $form->bodyToString(),
                'timeout' => 60,
            ]);
            $status = $uploadRes->getStatusCode();
            $uploadData = json_decode($uploadRes->getContent(false), true);
            if ($status >= 400 || !is_array($uploadData)) {
                $message = is_array($uploadData) ? ($uploadData['error']['message'] ?? 'Unknown error') : 'Upload failed';
                throw new \RuntimeException('OpenAI file upload failed: ' . (string)$message);
            }
            $fileId = (string)($uploadData['id'] ?? '');
            if ($fileId === '') {
                throw new \RuntimeException('OpenAI file upload succeeded but returned no file id.');
            }

            // Attach uploaded file to each configured vector store
            foreach ($vectorStoreIds as $vsId) {
                $attachRes = $this->httpClient->request('POST', $apiBase . '/vector_stores/' . urlencode($vsId) . '/files', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => ['file_id' => $fileId],
                    'timeout' => 60,
                ]);
                $attachStatus = $attachRes->getStatusCode();
                if ($attachStatus >= 400) {
                    $attachRaw = $attachRes->getContent(false);
                    $attachData = json_decode($attachRaw, true);
                    $msg = is_array($attachData) ? ($attachData['error']['message'] ?? ('HTTP ' . $attachStatus)) : ('HTTP ' . $attachStatus);
                    throw new \RuntimeException(sprintf('Failed attaching file to vector store %s: %s', $vsId, (string)$msg));
                }
            }

            return new JsonResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/products/{id}', name: 'api_products_delete', methods: ['DELETE'])]
    /**
     * Remove a product from the vector store.
     *
     * Example: `DELETE /api/products/1`
     *
     * Returns `{ "success": true }` when the vectors were deleted.
     */
    public function deleteProduct(int $id): JsonResponse
    {
        if (empty($id) || $id < 1) {
            return new JsonResponse(['message' => 'ID is empty or negative'], 400);
        }

        try {
            $this->vectorStoreService->deleteProductVectors($id);
            return new JsonResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 500);
        }
    }
}
