<?php

namespace App\Controller;

use App\Service\EmbeddingGeneratorInterface;
use App\Service\VectorStoreInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ProductFinderController extends AbstractController
{
    private EmbeddingGeneratorInterface $embeddingGenerator;
    private VectorStoreInterface $vectorStoreService;

    public function __construct(
        EmbeddingGeneratorInterface $embeddingGenerator,
        VectorStoreInterface $vectorStoreService
    ) {
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorStoreService = $vectorStoreService;
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
        $history = $data['history'] ?? []; // <-- History auslesen

        if (empty($message)) {
            return $this->json([
                'success' => false,
                'message' => 'Message parameter is required',
                'response' => null,
                'products' => []
            ], 400);
        }

        // History an Intent-Extraktion übergeben
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
        // Beispiel: Nutze die letzte Nutzer-Nachricht als Such-Query
        // Hier könntest du auch komplexere Logik/NLP einsetzen
        return $message;
    }

    /**
     * Generate a chat response based on the search results
     */
    private function generateChatResponse(string $originalMessage, string $searchQuery, array $results, array $history = []): string
    {
        // Du kannst die History hier für Kontext verwenden, z.B. für Follow-Ups
        if (empty($results)) {
            return "Es tut mir leid, ich konnte keine passenden Produkte zu Ihrer Anfrage finden. Können Sie Ihre Anforderungen genauer beschreiben?";
        }

        $count = count($results);
        $productNames = array_map(function($result) {
            return $result['title'] ?? 'Unbekanntes Produkt';
        }, array_slice($results, 0, 3));

        $productList = implode(', ', $productNames);
        if ($count > 3) {
            $productList .= ' und ' . ($count - 3) . ' weitere';
        }

        return "Basierend auf Ihrer Anfrage \"$originalMessage\" habe ich folgende Produkte gefunden: $productList. Möchten Sie mehr Details zu einem bestimmten Produkt?";
    }
}
