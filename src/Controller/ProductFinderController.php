<?php

namespace App\Controller;

use App\Service\EmbeddingGeneratorInterface;
use App\Service\PromptServiceInterface;
use App\Service\VectorStoreInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ProductFinderController extends AbstractController
{
    private EmbeddingGeneratorInterface $embeddingGenerator;
    private VectorStoreInterface $vectorStoreService;
    private PromptServiceInterface $promptService;

    public function __construct(
        EmbeddingGeneratorInterface $embeddingGenerator,
        VectorStoreInterface $vectorStoreService,
        PromptServiceInterface $promptService
    ) {
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorStoreService = $vectorStoreService;
        $this->promptService = $promptService;
    }

    #[Route('/api/products/search', name: 'api_products_search', methods: ['POST'])]
    public function searchProducts(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $query = $data['query'] ?? '';

        if (empty($query)) {
            return $this->json([
                'success' => false,
                'message' => 'Query parameter is required',
                'products' => []
            ], 400);
        }

        // Generate embedding for the query
        $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($query);

        // Search for similar products
        $results = $this->vectorStoreService->searchSimilarProducts($queryEmbedding);

        return $this->json([
            'success' => true,
            'query' => $query,
            'products' => $results
        ]);
    }

    #[Route('/api/products/chat', name: 'api_products_chat', methods: ['POST'])]
    public function chatSearch(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $message = $data['message'] ?? '';
        $history = $data['history'] ?? []; // <-- Read history

        if (empty($message)) {
            return $this->json([
                'success' => false,
                'message' => 'Message parameter is required',
                'response' => null,
                'products' => []
            ], 400);
        }

        // Pass history to intent extraction
        $searchQuery = $this->extractSearchIntent($message, $history);

        $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($searchQuery);
        $results = $this->vectorStoreService->searchSimilarProducts($queryEmbedding);
        $responseMessage = $this->generateChatResponse($message, $searchQuery, $results, $history);

        return $this->json([
            'success' => true,
            'query' => $searchQuery,
            'response' => $responseMessage,
            'products' => $results
        ]);
    }

    /**
     * Extract search intent from a chat message
     */
    private function extractSearchIntent(string $message, array $history = []): string
    {
        // Example: Use the last user message as search query
        // You could also implement more complex logic/NLP here
        return $message;
    }

    /**
     * Generate a chat response based on the search results
     */
    private function generateChatResponse(string $originalMessage, string $searchQuery, array $results, array $history = []): string
    {
        // You can use the history here for context, e.g., for follow-ups
        if (empty($results)) {
            return $this->promptService->getPrompt('product_finder_controller', 'no_results_message');
        }

        $count = count($results);
        $productNames = array_map(function($result) {
            return $result['title'] ?? 'Unknown Product';
        }, array_slice($results, 0, 3));

        $productList = implode(', ', $productNames);
        if ($count > 3) {
            $productList .= ' and ' . ($count - 3) . ' more';
        }

        return $this->promptService->getPrompt('product_finder_controller', 'results_found_template', [
            'original_message' => $originalMessage,
            'product_list' => $productList
        ]);
    }
}
