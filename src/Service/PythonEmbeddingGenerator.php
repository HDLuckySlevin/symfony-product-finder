<?php

namespace App\Service;

// Product entity is no longer directly used here
// use App\Entity\Product;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
// Removed MimeTypes as it's not used

class PythonEmbeddingGenerator implements EmbeddingGeneratorInterface
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private string $embedHost;
    private string $embedPort;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        string $embedHost,
        string $embedPort
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->embedHost = $embedHost; // e.g. 'http://localhost'
        $this->embedPort = $embedPort; // e.g. '5000'

        $this->performHealthCheck();
    }

    private function getServiceUrl(): string
    {
        return $this->embedHost . ':' . $this->embedPort;
    }

    private function performHealthCheck(): void
    {
        $url = $this->getServiceUrl() . '/healthstatus';
        $this->logger->info('Performing health check for Python embedding service at ' . $url);
        try {
            $response = $this->httpClient->request('GET', $url);
            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Python embedding service health check failed.', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getContent(false),
                ]);
                throw new \RuntimeException('Python embedding service is not healthy. Status code: ' . $response->getStatusCode());
            }
            $content = $response->toArray();
            if (!isset($content['status']) || $content['status'] !== 'It works') {
                $this->logger->error('Python embedding service health check failed.', [
                    'response_content' => $content,
                ]);
                throw new \RuntimeException('Python embedding service health check failed: Invalid response content.');
            }
            $this->logger->info('Python embedding service is healthy.');
        } catch (\Throwable $e) {
            $this->logger->error('Error during Python embedding service health check: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new \RuntimeException('Failed to connect to Python embedding service: ' . $e->getMessage(), 0, $e);
        }
    }

    public function generateTextEmbedding(string $text): array
    {
        $this->logger->info('Generating text embedding for text chunk.', [
            'text_length' => strlen($text)
        ]);

        if (empty(trim($text))) {
            $this->logger->warning('Empty text provided for embedding.');
            return [];
        }

        // The private generateTextEmbeddings method expects an array of texts
        // and returns an array of vectors. We send one text and expect one vector.
        $embeddingsArray = $this->generateTextEmbeddingsInternal([$text]);
        return $embeddingsArray[0] ?? [];
    }

    public function generateImageEmbedding(string $imageUrl): array
    {
        $this->logger->info('Generating image embedding for URL.', ['image_url' => $imageUrl]);

        if (empty(trim($imageUrl)) || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $this->logger->warning('Invalid or empty image URL provided for embedding.', ['image_url' => $imageUrl]);
            return [];
        }

        $url = $this->getServiceUrl() . '/image-embedding';
        $this->logger->debug('Sending image embedding request to ' . $url, ['image_url' => $imageUrl]);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => ['url' => $imageUrl], // Assuming the endpoint expects a URL
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Failed to generate image embedding from Python service.', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getContent(false),
                    'image_url' => $imageUrl,
                ]);
                throw new \RuntimeException('Failed to generate image embedding. Status: ' . $response->getStatusCode());
            }

            $data = $response->toArray();
            // Assuming the response for a single image is {'vector': [0.1, 0.2, ...]}
            if (!isset($data['vector']) || !is_array($data['vector'])) {
                $this->logger->error('Invalid response format from Python image embedding service.', [
                    'response' => $data,
                    'image_url' => $imageUrl,
                ]);
                throw new \RuntimeException('Invalid response format from Python image embedding service.');
            }

            $this->logger->info('Successfully generated image embedding.', ['image_url' => $imageUrl, 'vector_size' => count($data['vector'])]);
            return $data['vector'];

        } catch (\Throwable $e) {
            $this->logger->error('Error generating image embedding: ' . $e->getMessage(), [
                'exception' => $e,
                'image_url' => $imageUrl,
            ]);
            // It's important to decide if we should throw an exception or return empty array on failure.
            // For batch processing, returning an empty array might be more robust.
            return [];
            // throw new \RuntimeException('Error generating image embedding: ' . $e->getMessage(), 0, $e);
        }
    }

    public function generateQueryEmbedding(string $query): array
    {
        $this->logger->info('Generating text embedding for query', ['query_length' => strlen($query)]);
        if (empty($query)) {
            $this->logger->warning('Empty query provided for embedding.');
            return [];
        }
        // Use the internal method that handles arrays of texts
        $embeddingsArray = $this->generateTextEmbeddingsInternal([$query]);
        return $embeddingsArray[0] ?? [];
    }

    /**
     * Generates embeddings for a list of texts using the Python service.
     * This method is kept private as the interface now deals with single text/image.
     *
     * @param array<string> $texts
     * @return array<int, array<float>>
     */
    private function generateTextEmbeddingsInternal(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $url = $this->getServiceUrl() . '/text-embedding';
        $this->logger->debug('Sending text embedding request to ' . $url, ['text_count' => count($texts)]);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => ['texts' => $texts],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Failed to generate text embeddings from Python service.', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getContent(false),
                ]);
                // Return empty array for robustness in batch processing
                return array_fill(0, count($texts), []);
            }

            $data = $response->toArray();
            if (!isset($data['vectors']) || !is_array($data['vectors']) || count($data['vectors']) !== count($texts)) {
                $this->logger->error('Invalid response format or mismatched vector count from Python text embedding service.', [
                    'response' => $data,
                    'expected_count' => count($texts)
                ]);
                 // Return empty array for robustness
                return array_fill(0, count($texts), []);
            }

            $this->logger->info('Successfully generated text embeddings.', ['vector_count' => count($data['vectors'])]);
            return $data['vectors'];

        } catch (\Throwable $e) {
            $this->logger->error('Error generating text embeddings: ' . $e->getMessage(), [
                'exception' => $e,
                'texts' => $texts, // Be cautious logging potentially large/sensitive texts
            ]);
             // Return empty array for robustness
            return array_fill(0, count($texts), []);
            // throw new \RuntimeException('Error generating text embeddings: ' . $e->getMessage(), 0, $e);
        }
    }
    // The getProductTextForEmbedding method is no longer needed as text is chunked before calling this service.
}
