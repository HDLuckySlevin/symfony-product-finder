<?php

namespace App\Service;

use App\Entity\Product;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

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

    public function generateProductEmbeddings(Product $product): array
    {
        $this->logger->info('Generating embeddings for product', [
            'product_id' => $product->getId(),
            'product_name' => $product->getName()
        ]);

        $texts = [];

        if ($product->getName()) {
            $texts[] = ['text' => $product->getName(), 'type' => 'generic'];
        }
        if ($product->getBrand()) {
            $texts[] = ['text' => $product->getBrand(), 'type' => 'generic'];
        }
        if ($product->getCategory()) {
            $texts[] = ['text' => $product->getCategory(), 'type' => 'generic'];
        }
        if ($product->getDescription()) {
            $texts[] = ['text' => $product->getDescription(), 'type' => 'description'];
        }

        $specs = $product->getSpecifications();
        if (!empty($specs)) {
            foreach ($specs as $name => $value) {
                $texts[] = [
                    'text' => sprintf('Spezifikation – %s: %s', $name, $value),
                    'type' => 'specification'
                ];
            }
        }

        $features = $product->getFeatures();
        if (!empty($features)) {
            foreach ($features as $feature) {
                $texts[] = ['text' => $feature, 'type' => 'feature'];
            }
        }

        $chunks = [];

        if (!empty($texts)) {
            $embeddings = $this->generateTextEmbeddings(array_column($texts, 'text'));
            foreach ($embeddings as $index => $vector) {
                if (!empty($vector)) {
                    $chunks[] = [
                        'vector' => $vector,
                        'type' => $texts[$index]['type'] ?? 'generic'
                    ];
                }
            }
        }

        // Handle image embedding
        $imageUrl = $product->getImageUrl();
        if ($imageUrl && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $vectors = $this->generateImageEmbeddings([$imageUrl]);
            if (!empty($vectors[0])) {
                $chunks[] = ['vector' => $vectors[0], 'type' => 'image'];
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
                    $extension = pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
                    $mimeTypes = new MimeTypes();
                    $contentType = $mimeTypes->getMimeTypes($extension)[0] ?? 'application/octet-stream';
                }

                $filename = basename(parse_url($imageUrl, PHP_URL_PATH) ?: 'image');
                if ($filename === '') {
                    $filename = 'image';
                }

                $dataPart = new DataPart($imageData, $filename, $contentType);
                $formData = new FormDataPart(['file' => $dataPart]);

                $response = $this->httpClient->request('POST', $endpoint, [
                    'headers' => $formData->getPreparedHeaders()->toArray(),
                    'body' => $formData->bodyToIterable(),
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
            $dataPart = new DataPart(file_get_contents($imagePath), basename($imagePath));
            $formData = new FormDataPart(['file' => $dataPart]);

            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
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

    // Removed generateImageEmbedding method
}
