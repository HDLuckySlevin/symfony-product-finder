<?php

namespace App\Controller;

use App\Service\EmbeddingGeneratorInterface;
use App\Service\PromptServiceInterface;
use App\Service\SearchServiceInterface;
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
    private SearchServiceInterface $searchService;

    public function __construct(
        EmbeddingGeneratorInterface $embeddingGenerator,
        VectorStoreInterface $vectorStoreService,
        SearchServiceInterface $searchService,
        PromptServiceInterface $promptService
    ) {
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorStoreService = $vectorStoreService;
        $this->searchService = $searchService;
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

        try {
            // Generate embedding for the query
            $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($query);

            // Search for similar products
            $results = $this->vectorStoreService->searchSimilarProducts($queryEmbedding, 3);

            if (empty($results)) {
                return $this->json([
                    'success' => true,
                    'query' => $query,
                    'message' => 'No products found matching the query',
                    'products' => []
                ]);
            }

            // Filter results to only include products with distance <= 0.5
            $filteredResults = array_filter($results, function($result) {
                return isset($result['distance']) && $result['distance'] <= 0.5;
            });

            if (empty($filteredResults)) {
                return $this->json([
                    'success' => true,
                    'query' => $query,
                    'message' => 'No products found with sufficient relevance to the query',
                    'products' => []
                ]);
            }

            return $this->json([
                'success' => true,
                'query' => $query,
                'products' => $filteredResults
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred during search: ' . $e->getMessage(),
                'products' => []
            ], 500);
        }
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

        try {
            // Generate embedding for the query
            $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($searchQuery);

            // Search for similar products
            $results = $this->vectorStoreService->searchSimilarProducts($queryEmbedding, 3);

            if (empty($results)) {
                $noResultsMessage = $this->promptService->getPrompt('product_finder', 'no_results_message');
                return $this->json([
                    'success' => true,
                    'query' => $searchQuery,
                    'response' => $noResultsMessage,
                    'products' => []
                ]);
            }

            // Filter results to only include products with distance <= 0.5
            $filteredResults = array_filter($results, function($result) {
                return isset($result['distance']) && $result['distance'] <= 0.5;
            });

            if (empty($filteredResults)) {
                $noResultsMessage = $this->promptService->getPrompt('product_finder', 'no_results_message');
                return $this->json([
                    'success' => true,
                    'query' => $searchQuery,
                    'response' => $noResultsMessage,
                    'products' => []
                ]);
            }

            // Create system prompt that acts as a product finder
            $systemPromptContent = $this->promptService->getPrompt('product_finder', 'system_prompt');
            $systemPrompt = [
                'role' => 'system',
                'content' => $systemPromptContent
            ];

            // Create user message with query and products
            $productsList = '';
            foreach ($filteredResults as $index => $result) {
                $productsList .= ($index + 1) . ". " . ($result['title'] ?? 'Unknown product') . " (Similarity: " . (1 - ($result['distance'] ?? 0)) . ")\n";
            }

            $userMessageContent = $this->promptService->getPrompt('product_finder', 'user_message_template', [
                'query' => $searchQuery,
                'products_list' => $productsList
            ]);

            $userMessage = [
                'role' => 'user',
                'content' => $userMessageContent
            ];

            $messages = [$systemPrompt, $userMessage];
            $recommendation = $this->searchService->generateChatCompletion($messages);

            return $this->json([
                'success' => true,
                'query' => $searchQuery,
                'response' => $recommendation,
                'products' => $filteredResults
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred during search: ' . $e->getMessage(),
                'response' => null,
                'products' => []
            ], 500);
        }
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

}
