<?php

namespace App\Service;

use App\Entity\Product;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PythonEmbeddingService implements EmbeddingGeneratorInterface
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

    public function getVectorDimension(): int
    {
        $url = $this->getServiceUrl() . '/dimension';
        try {
            $response = $this->httpClient->request('GET', $url);
            if ($response->getStatusCode() === 200) {
                $data = $response->toArray(false);
                if (isset($data['dimension']) && is_numeric($data['dimension'])) {
                    return (int) $data['dimension'];
                }
                $this->logger->warning('Invalid dimension response from embedding service', [
                    'response' => $data,
                ]);
            } else {
                $this->logger->warning('Unexpected status code when fetching dimension', [
                    'status_code' => $response->getStatusCode(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch dimension from embedding service', [
                'exception' => $e,
            ]);
        }

        return 1536;
    }

    public function generateProductEmbeddings(Product $product): array
    {
        $this->logger->info('Generating embeddings for product', [
            'product_id' => $product->getId(),
            'product_name' => $product->getName()
        ]);

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

        $specs = $product->getSpecifications();
        if (!empty($specs)) {
            foreach ($specs as $name => $value) {
                $parts[] = sprintf('%s: %s', $name, $value);
            }
        }

        $features = $product->getFeatures();
        if (!empty($features)) {
            foreach ($features as $feature) {
                $parts[] = $feature;
            }
        }

        $text = trim(implode("\n", $parts));
        $chunks = [];

        if ($text !== '') {
            $vector = $this->generateTextEmbeddings([$text])[0] ?? [];
            if (!empty($vector)) {
                $chunks[] = [
                    'vector' => $vector,
                    'type' => 'product',
                ];
            }
        }

        $this->logger->info('Generated embeddings for product chunks', ['chunk_count' => count($chunks)]);

        return $chunks;
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

    /**
     * Generates embeddings for a list of image URLs using the Python service.
     * Each image is downloaded and sent as multipart/form-data.
     *
     * @param array<string> $urls
     * @return array<int, array<float>>
     */
    private function generateImageEmbeddings(array $urls): array
    {
        $endpoint = $this->getServiceUrl() . '/image-embedding';
        $vectors = [];

        foreach ($urls as $imageUrl) {
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $this->logger->warning('Skipping invalid image URL for embedding', ['url' => $imageUrl]);
                continue;
            }

            try {
                $download = $this->httpClient->request('GET', $imageUrl);
                if ($download->getStatusCode() !== 200) {
                    $this->logger->error('Failed to download image for embedding', [
                        'url' => $imageUrl,
                        'status_code' => $download->getStatusCode(),
                    ]);
                    continue;
                }

                $imageData = $download->getContent();
                $contentType = $download->getHeaders(false)['content-type'][0] ?? null;

                if (!$contentType) {
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $contentType = $finfo->buffer($imageData) ?: 'application/octet-stream';
                }

                $filename = basename(parse_url($imageUrl, PHP_URL_PATH) ?: 'image');
                if ($filename === '') {
                    $filename = 'image';
                }

                $boundary = '----pfb' . bin2hex(random_bytes(16));
                $body = "--$boundary\r\n".
                        "Content-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n".
                        "Content-Type: $contentType\r\n\r\n".
                        $imageData."\r\n".
                        "--$boundary--\r\n";

                $response = $this->httpClient->request('POST', $endpoint, [
                    'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
                    'body' => $body,
                ]);

                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Failed to generate image embedding from Python service.', [
                        'status_code' => $response->getStatusCode(),
                        'response' => $response->getContent(false),
                    ]);
                    continue;
                }

                $data = $response->toArray(false);
                if (!isset($data['vector']) || !is_array($data['vector'])) {
                    $this->logger->error('Invalid response format from Python image embedding service.', [
                        'response' => $data,
                    ]);
                    continue;
                }

                $vectors[] = $data['vector'];

            } catch (\Throwable $e) {
                $this->logger->error('Error generating image embedding', [
                    'exception' => $e,
                    'url' => $imageUrl,
                ]);
            }
        }

        $this->logger->info('Successfully generated image embeddings.', ['vector_count' => count($vectors)]);
        return $vectors;
    }

    /**
     * Sends a local image file to the Python service and returns the generated description.
     */
    public function describeImage(string $imagePath): ?string
    {
        if (!is_file($imagePath)) {
            $this->logger->error('Image file not found for description', ['path' => $imagePath]);
            return null;
        }

        $endpoint = $this->getServiceUrl() . '/image-embedding';

        try {
            $imageData = file_get_contents($imagePath);
            if ($imageData === false) {
                $this->logger->error('Failed to read image file for description', ['path' => $imagePath]);
                return null;
            }
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $contentType = $finfo->file($imagePath) ?: 'application/octet-stream';

            $filename = basename($imagePath);
            $boundary = '----pfb' . bin2hex(random_bytes(16));
            $body = "--$boundary\r\n".
                    "Content-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n".
                    "Content-Type: $contentType\r\n\r\n".
                    $imageData."\r\n".
                    "--$boundary--\r\n";

            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
                'body' => $body,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Failed to describe image via Python service', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getContent(false),
                ]);
                return null;
            }

            $data = $response->toArray(false);
            if (!isset($data['description'])) {
                $this->logger->error('Python image embedding service response missing description', [
                    'response' => $data,
                ]);
                return null;
            }

            return $data['description'];

        } catch (\Throwable $e) {
            $this->logger->error('Error describing image', [
                'exception' => $e,
                'path' => $imagePath,
            ]);
            return null;
        }
    }

    public function getActiveEmbeddingModel(): array
    {
        $url = $this->getServiceUrl() . '/activeembeddingmodell';
        try {
            $response = $this->httpClient->request('GET', $url);
            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Failed to fetch active embedding model', [
                    'status_code' => $response->getStatusCode(),
                ]);
                return [];
            }

            $data = $response->toArray(false);
            $this->logger->info('Fetched active embedding model', ['data' => $data]);
            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('Error fetching active embedding model', [
                'exception' => $e,
            ]);
            return [];
        }
    }

    public function changeEmbeddingModel(string $provider, string $modelName): array
    {
        $url = $this->getServiceUrl() . '/changeembeddingmodell';
        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => [
                    'embedding_provider' => $provider,
                    'model_name' => $modelName,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Failed to change embedding model', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getContent(false),
                ]);
                return [];
            }

            $data = $response->toArray(false);
            $this->logger->info('Changed embedding model', ['data' => $data]);
            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('Error changing embedding model', [
                'exception' => $e,
            ]);
            return [];
        }
    }

    // Removed generateImageEmbedding method
}
