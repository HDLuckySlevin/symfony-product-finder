<?php

namespace App\Controller;

use App\DTO\Request\ChatRequestDto;
use App\DTO\Response\ChatResponseDto;
use App\DTO\Response\ProductResponseDto;
use App\Service\EmbeddingGeneratorInterface;
use App\Service\OpenAIEmbeddingService;
use App\Service\PromptServiceInterface;
use App\Service\SearchServiceInterface;
use App\Service\SpeechToTextServiceInterface;
use App\Service\VectorStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class ApiSearchController extends AbstractController
{
    private EmbeddingGeneratorInterface $embeddingGenerator;
    private VectorStoreInterface $vectorStoreService;
    private PromptServiceInterface $promptService;
    private SearchServiceInterface $searchService;
    private OpenAIEmbeddingService $embeddingService;
    private SpeechToTextServiceInterface $sttService;
    private LoggerInterface $logger;

    public function __construct(
        EmbeddingGeneratorInterface $embeddingGenerator,
        VectorStoreInterface $vectorStoreService,
        SearchServiceInterface $searchService,
        PromptServiceInterface $promptService,
        OpenAIEmbeddingService $embeddingService,
        SpeechToTextServiceInterface $sttService,
        LoggerInterface $logger
    ) {
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorStoreService = $vectorStoreService;
        $this->searchService = $searchService;
        $this->promptService = $promptService;
        $this->embeddingService = $embeddingService;
        $this->sttService = $sttService;
        $this->logger = $logger;
    }

    /**
     * Execute search based on an embedding vector and query string.
     *
     * @param array<int, float> $vector
     */
    private function runSearch(array $vector, string $query): ChatResponseDto
    {
        $this->logger->info('Running product search', [
            'query' => $query,
            'vector_length' => count($vector),
        ]);

        $results = $this->vectorStoreService->searchSimilarProducts($vector, 3);

        $this->logger->debug('Vector search results', [
            'count' => count($results),
        ]);

        if (empty($results)) {
            $this->logger->info('No results from vector store', ['query' => $query]);
            $noResultsMessage = $this->promptService->getPrompt('product_finder', 'no_results_message');
            return new ChatResponseDto(true, $query, null, $noResultsMessage, []);
        }

        $filteredResults = array_filter($results, static fn($r) => isset($r['distance']) && $r['distance'] <= 0.5);

        $this->logger->debug('Filtered results', [
            'count' => count($filteredResults),
        ]);
        if (empty($filteredResults)) {
            $this->logger->info('No results after filtering', ['query' => $query]);
            $noResultsMessage = $this->promptService->getPrompt('product_finder', 'no_results_message');
            return new ChatResponseDto(true, $query, null, $noResultsMessage, []);
        }

        $systemPromptContent = $this->promptService->getPrompt('product_finder', 'system_prompt');
        $systemPrompt = [
            'role' => 'system',
            'content' => $systemPromptContent,
        ];

        $productsList = '';
        foreach ($filteredResults as $index => $result) {
            $productsList .= ($index + 1) . '. ' . ($result['title'] ?? 'Unknown product') . ' (Similarity: ' . (($result['distance'] ?? 0)) . ")\n";
        }

        $userMessageContent = $this->promptService->getPrompt('product_finder', 'user_message_template', [
            'query' => $query,
            'products_list' => $productsList,
        ]);

        $messages = [
            $systemPrompt,
            [
                'role' => 'user',
                'content' => $userMessageContent,
            ],
        ];
        $recommendation = $this->searchService->generateChatCompletion($messages);

        $this->logger->info('Generated recommendation', [
            'query' => $query,
            'product_count' => count($filteredResults),
        ]);

        $productDtos = array_map(static fn($r) => ProductResponseDto::fromArray($r), $filteredResults);

        $this->logger->info('Search finished', [
            'query' => $query,
            'returned_products' => count($productDtos),
        ]);

        return new ChatResponseDto(true, $query, null, $recommendation, $productDtos);
    }

    #[Route('/api/search/text', name: 'api_search_text', methods: ['POST'])]
    public function searchText(#[MapRequestPayload] ChatRequestDto $chatRequest): JsonResponse
    {
        $query = $chatRequest->message;
        if ($query === '') {
            $this->logger->warning('Text search with empty query');
            return new JsonResponse(['success' => false, 'message' => 'Message parameter is required'], 400);
        }
        $this->logger->info('Text search request', ['query' => $query]);
        $vector = $this->embeddingGenerator->generateQueryEmbedding($query);
        $response = $this->runSearch($vector, $query);
        return $this->json($response);
    }

    #[Route('/api/search/image', name: 'api_search_image', methods: ['POST'])]
    public function searchImage(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile) {
            $this->logger->warning('Image search without file');
            return new JsonResponse(['success' => false, 'message' => 'No image uploaded'], 400);
        }
        $this->logger->info('Image search request', [
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowed, true)) {
            $this->logger->warning('Invalid image type', ['mime' => $file->getMimeType()]);
            return new JsonResponse(['success' => false, 'message' => 'Invalid image type'], 400);
        }
        $result = $this->embeddingService->describeImageFile($file);
        $vector = $result['vector'] ?? [];
        $description = (string) ($result['description'] ?? '');
        $this->logger->debug('Image description', ['description' => $description]);
        $response = $this->runSearch($vector, $description);
        return $this->json($response);
    }

    #[Route('/api/search/audio', name: 'api_search_audio', methods: ['POST'])]
    public function searchAudio(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('audio');
        if (!$file instanceof UploadedFile) {
            $this->logger->warning('Audio search without file');
            return new JsonResponse(['success' => false, 'message' => 'No audio uploaded'], 400);
        }
        $this->logger->info('Audio search request', [
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
        $temp = tempnam(sys_get_temp_dir(), 'aud');
        if ($temp === false) {
            $this->logger->error('Unable to create temp file for audio');
            return new JsonResponse(['success' => false, 'message' => 'Could not create temp file'], 500);
        }
        $file->move(dirname($temp), basename($temp));
        $text = $this->sttService->transcribe($temp);
        @unlink($temp);
        if ($text === null || $text === '') {
            $this->logger->warning('Transcription failed');
            return new JsonResponse(['success' => false, 'message' => 'Transcription failed'], 500);
        }
        $this->logger->debug('Transcribed audio', ['text' => $text]);
        $vector = $this->embeddingGenerator->generateQueryEmbedding($text);
        $response = $this->runSearch($vector, $text);
        return $this->json($response);
    }
}
