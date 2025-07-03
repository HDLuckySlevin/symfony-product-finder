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

    public function __construct(
        EmbeddingGeneratorInterface $embeddingGenerator,
        VectorStoreInterface $vectorStoreService,
        SearchServiceInterface $searchService,
        PromptServiceInterface $promptService,
        OpenAIEmbeddingService $embeddingService,
        SpeechToTextServiceInterface $sttService
    ) {
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorStoreService = $vectorStoreService;
        $this->searchService = $searchService;
        $this->promptService = $promptService;
        $this->embeddingService = $embeddingService;
        $this->sttService = $sttService;
    }

    /**
     * Execute search based on an embedding vector and query string.
     *
     * @param array<int, float> $vector
     */
    private function runSearch(array $vector, string $query): ChatResponseDto
    {
        $results = $this->vectorStoreService->searchSimilarProducts($vector, 3);

        if (empty($results)) {
            $noResultsMessage = $this->promptService->getPrompt('product_finder', 'no_results_message');
            return new ChatResponseDto(true, $query, null, $noResultsMessage, []);
        }

        $filteredResults = array_filter($results, static fn($r) => isset($r['distance']) && $r['distance'] <= 0.5);
        if (empty($filteredResults)) {
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

        $productDtos = array_map(static fn($r) => ProductResponseDto::fromArray($r), $filteredResults);

        return new ChatResponseDto(true, $query, null, $recommendation, $productDtos);
    }

    #[Route('/api/search/text', name: 'api_search_text', methods: ['POST'])]
    public function searchText(#[MapRequestPayload] ChatRequestDto $chatRequest): JsonResponse
    {
        $query = $chatRequest->message;
        if ($query === '') {
            return new JsonResponse(['success' => false, 'message' => 'Message parameter is required'], 400);
        }
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
            return new JsonResponse(['success' => false, 'message' => 'No image uploaded'], 400);
        }
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowed, true)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid image type'], 400);
        }
        $result = $this->embeddingService->describeImageFile($file);
        $vector = $result['vector'] ?? [];
        $description = (string) ($result['description'] ?? '');
        $response = $this->runSearch($vector, $description);
        return $this->json($response);
    }

    #[Route('/api/search/audio', name: 'api_search_audio', methods: ['POST'])]
    public function searchAudio(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('audio');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['success' => false, 'message' => 'No audio uploaded'], 400);
        }
        $temp = tempnam(sys_get_temp_dir(), 'aud');
        if ($temp === false) {
            return new JsonResponse(['success' => false, 'message' => 'Could not create temp file'], 500);
        }
        $file->move(dirname($temp), basename($temp));
        $text = $this->sttService->transcribe($temp);
        @unlink($temp);
        if ($text === null || $text === '') {
            return new JsonResponse(['success' => false, 'message' => 'Transcription failed'], 500);
        }
        $vector = $this->embeddingGenerator->generateQueryEmbedding($text);
        $response = $this->runSearch($vector, $text);
        return $this->json($response);
    }
}
