<?php

namespace App\Service;

use App\Entity\Product;
use OpenAI\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Embedding service using OpenAI directly via openai-php/client.
 */
class OpenAIEmbeddingService implements EmbeddingGeneratorInterface
{
    private Client $client;
    private LoggerInterface $logger;
    private string $embeddingModel;
    private string $imageModel;
    private bool $debugVectors;
    private string $imageDescriptionPrompt;

    public function __construct(
        Client $client,
        LoggerInterface $logger,
        string $embeddingModel = 'text-embedding-3-small',
        string $imageModel = 'gpt-4o',
        bool $debugVectors = false,
        string $imageDescriptionPrompt = null
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->embeddingModel = $embeddingModel;
        $this->imageModel = $imageModel;
        $this->debugVectors = $debugVectors;
        $this->imageDescriptionPrompt = $imageDescriptionPrompt;
    }


    public function getVectorDimension(): int
    {
        $map = [
            'text-embedding-3-large' => 3072,
            'text-embedding-3-small' => 1536,
            'text-embedding-ada-002' => 1536,
        ];
        return $map[$this->embeddingModel] ?? 1536;
    }

    public function healthStatus(): array
    {
        return ['status' => 'It works', 'provider' => 'openai'];
    }

    /**
     * @param array<string> $texts
     * @return array<int, array<float>>
     */
    public function createTextEmbeddings(array $texts): array
    {
        try {
            $response = $this->client->embeddings()->create([
                'model' => $this->embeddingModel,
                'input' => $texts
            ]);

            $vectors = [];
            foreach ($response->embeddings as $datum) {
                $vectors[] = $datum->embedding;
            }

            if ($this->debugVectors) {
                $this->logger->info(json_encode($vectors));
            }

            return $vectors;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create text embeddings', ['exception' => $e]);
            throw new \RuntimeException('Failed to create text embeddings');
        }
    }

    /**
     * @return array{description: string, vector: array<float>, provider: string}
     */
    public function describeImageFile(UploadedFile $file): array
    {
        try {
            $data = base64_encode($file->getContent());
            $messages = [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $this->imageDescriptionPrompt],
                        [
                            'type' => 'image_url',
                            'image_url' => ['url' => 'data:image/jpeg;base64,' . $data, 'detail' => 'high']
                        ],
                    ],
                ],
            ];

            $response = $this->client->chat()->create([
                'model' => $this->imageModel,
                'messages' => $messages,
                'max_tokens' => 300,
            ]);

            if (empty($response->choices) || !$response->choices[0]->message->content) {
                throw new \RuntimeException('OpenAI returned empty response');
            }
            $description = trim($response->choices[0]->message->content);

            $embed = $this->client->embeddings()->create([
                'model' => $this->embeddingModel,
                'input' => [$description]
            ]);

            if (empty($embed->embeddings)) {
                throw new \RuntimeException('OpenAI returned empty embeddings');
            }
            $vector = $embed->embeddings[0]->embedding;

            if ($this->debugVectors) {
                $this->logger->info(json_encode($vector));
            }

            return [
                'description' => $description,
                'vector' => $vector,
                'provider' => 'openai',
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create image embedding', ['exception' => $e]);
            throw new \RuntimeException('OpenAI image description failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function generateProductEmbeddings(Product $product): array
    {
        $parts = [];
        if ($product->getName()) {
            $parts[] = $product->getName();
        }
        if ($product->getBrand()) {
            $parts[] = 'Brand: ' . $product->getBrand();
        }
        if ($product->getCategory()) {
            $parts[] = 'Category: ' . $product->getCategory();
        }
        if ($product->getDescription()) {
            $parts[] = $product->getDescription();
        }
        foreach ($product->getSpecifications() as $name => $value) {
            $parts[] = sprintf('%s: %s', $name, $value);
        }
        foreach ($product->getFeatures() as $feature) {
            $parts[] = $feature;
        }

        $text = trim(implode("\n", $parts));
        if ($text === '') {
            return [];
        }

        $vector = $this->createTextEmbeddings([$text])[0] ?? [];

        return [['vector' => $vector, 'type' => 'product']];
    }

    public function generateQueryEmbedding(string $query): array
    {
        if ($query === '') {
            return [];
        }
        return $this->createTextEmbeddings([$query])[0] ?? [];
    }

    public function describeImage(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $file = new UploadedFile($path, basename($path));
        $result = $this->describeImageFile($file);
        return $result['description'] ?? null;
    }
}
