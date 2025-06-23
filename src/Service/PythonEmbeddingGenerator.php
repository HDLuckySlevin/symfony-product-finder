<?php

namespace App\Service;

use App\Entity\Product;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\MimeTypes; // Import MimeTypes

class PythonEmbeddingGenerator implements EmbeddingGeneratorInterface
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private string $embedHost;
    private string $embedPort;
    // private MimeTypeGuesserInterface $mimeTypeGuesser; // Removed

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        // MimeTypeGuesserInterface $mimeTypeGuesser, // Removed
        string $embedHost,
        string $embedPort
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        // $this->mimeTypeGuesser = $mimeTypeGuesser; // Removed
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

    public function generateEmbedding(Product $product): array
    {
        $this->logger->info('Generating text embedding for product', [
            'product_id' => $product->getId(),
            'product_name' => $product->getName()
        ]);

        $textToEmbed = $this->getProductTextForEmbedding($product);
        if (empty($textToEmbed)) {
            $this->logger->warning('No text content found for product to embed.', ['product_id' => $product->getId()]);
            return [];
        }

        return $this->generateTextEmbeddings([$textToEmbed])[0] ?? [];
    }

    public function generateQueryEmbedding(string $query): array
    {
        $this->logger->info('Generating text embedding for query', ['query_length' => strlen($query)]);
        if (empty($query)) {
            $this->logger->warning('Empty query provided for embedding.');
            return [];
        }
        return $this->generateTextEmbeddings([$query])[0] ?? [];
    }

    /**
     * Generates embeddings for a list of texts using the Python service.
     *
     * @param array<string> $texts
     * @return array<int, array<float>>
     */
    private function generateTextEmbeddings(array $texts): array
    {
        $url = $this->getServiceUrl() . '/text-embedding';
        $this->logger->debug('Sending text embedding request to ' . $url, ['text_count' => count($texts)]);

        try {
            $response = $this->httpClient->request('POST', $url, [ // Changed to POST
                'json' => ['texts' => $texts],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Failed to generate text embeddings from Python service.', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getContent(false),
                ]);
                throw new \RuntimeException('Failed to generate text embeddings. Status: ' . $response->getStatusCode());
            }

            $data = $response->toArray();
            if (!isset($data['vectors']) || !is_array($data['vectors'])) {
                $this->logger->error('Invalid response format from Python text embedding service.', ['response' => $data]);
                throw new \RuntimeException('Invalid response format from Python text embedding service.');
            }

            $this->logger->info('Successfully generated text embeddings.', ['vector_count' => count($data['vectors'])]);
            return $data['vectors'];

        } catch (\Throwable $e) {
            $this->logger->error('Error generating text embeddings: ' . $e->getMessage(), [
                'exception' => $e,
                'texts' => $texts,
            ]);
            throw new \RuntimeException('Error generating text embeddings: ' . $e->getMessage(), 0, $e);
        }
    }
    // Removed generateImageEmbedding method

    private function getProductTextForEmbedding(Product $product): string
    {
        // This logic is similar to OpenAIEmbeddingGenerator's groupFieldData and getChunk
        $title = $product->getName() . ' ' . $product->getBrand();
        $metadata = $product->getCategory() . ' ' . $product->getDescription();

        $specifications = '';
        if (!empty($product->getSpecifications())) {
            foreach ($product->getSpecifications() as $key => $value) {
                $specifications .= ' ' . $key . ': ' . $value;
            }
        }

        $features = '';
        if (!empty($product->getFeatures())) {
            $features = implode(', ', $product->getFeatures());
        }

        $chunk = trim($title . ' ' . $metadata);
        if (!empty($specifications)) {
            $chunk .= ' Specifications: ' . trim($specifications);
        }
        if (!empty($features)) {
            $chunk .= ' Features: ' . trim($features);
        }
        return $chunk;
    }
}
