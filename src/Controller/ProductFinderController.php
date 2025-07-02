<?php

namespace App\Controller;

use App\DTO\Request\ChatRequestDto;
use App\DTO\Response\ChatResponseDto;
use App\DTO\Response\ProductResponseDto;
use App\Service\EmbeddingGeneratorInterface;
use App\Service\PromptServiceInterface;
use App\Service\SearchServiceInterface;
use App\Service\VectorStoreInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
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


    /**
     * Create a response for when no results are found
     */
    private function createNoResultsResponse(string $searchQuery): JsonResponse
    {
        $noResultsMessage = $this->promptService->getPrompt('product_finder', 'no_results_message');
        $response = new ChatResponseDto(
            true,
            $searchQuery,
            null,
            $noResultsMessage,
            []
        );
        return $this->json($response);
    }

    /**
     * Handles chat-based product search requests and returns product recommendations.
     *
     * Accepts a chat message as a search query, generates an embedding, retrieves similar products from a vector store, filters results by similarity, and uses prompt templates to generate a chat-based recommendation. Returns a structured JSON response containing the recommendation and matching products, or an error message if no suitable products are found or an error occurs.
     *
     * @param ChatRequestDto $chatRequest The incoming chat request containing the user's message.
     * @return JsonResponse JSON response with product recommendations, no-results message, or error details.
     */
    #[Route('/api/products/chat', name: 'api_products_chat', methods: ['POST'])]
    public function chatSearch(#[MapRequestPayload] ChatRequestDto $chatRequest, InputInterface $input, OutputInterface $output): JsonResponse
    {
        $message = $chatRequest->message;

        if (empty($message)) {
            $response = new ChatResponseDto(
                false,
                null,
                'Message parameter is required',
                null,
                []
            );
            return $this->json($response, 400);
        }

        // Use message directly as search query
        $searchQuery = $message;

        try {
            // Generate embedding for the query
            $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($searchQuery);

            // Search for similar products
            $results = $this->vectorStoreService->searchSimilarProducts($queryEmbedding, 3);

            if (empty($results)) {
                return $this->createNoResultsResponse($searchQuery);
            }

            // Filter results to only include products with distance <= 0.5
            $filteredResults = array_filter($results, static function($result) {
                return isset($result['distance']) && $result['distance'] <= 0.5;
            });

            if (empty($filteredResults)) {
                return $this->createNoResultsResponse($searchQuery);
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
                $productsList .= ($index + 1) . ". " . ($result['title'] ?? 'Unknown product') . " (Similarity: " . (($result['distance'] ?? 0)) . ")\n";
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

            // Convert results to ProductResponseDto objects
            $productDtos = array_map(static function($result) {
                return ProductResponseDto::fromArray($result);
            }, $filteredResults);

            $response = new ChatResponseDto(
                true,
                $searchQuery,
                null,
                $recommendation,
                $productDtos
            );

            return $this->json($response);
        } catch (\Exception $e) {
            $response = new ChatResponseDto(
                false,
                $searchQuery,
                'An error occurred during search: ' . $e->getMessage(),
                null,
                []
            );
            return $this->json($response, 500);
        }
    }


}
