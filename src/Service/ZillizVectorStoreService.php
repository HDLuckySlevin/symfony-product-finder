<?php

namespace App\Service;

use App\Entity\Product;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for interacting with the Zilliz vector database via REST API.
 */
class ZillizVectorStoreService implements VectorStoreInterface
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private OpenAIEmbeddingService $embeddingService;
    private string $apiKey;
    private string $host;
    private int $port;
    private string $collectionName;
    private int $dimension;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        OpenAIEmbeddingService $embeddingService,
        string $apiKey,
        string $host,
        int $port = 443,
        string $collectionName = 'default'
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->embeddingService = $embeddingService;
        $this->apiKey = $apiKey;
        $this->host = rtrim($host, '/');
        $this->port = $port;
        $this->collectionName = $collectionName;
        $this->dimension = $this->embeddingService->getVectorDimension();
    }

    private function endpoint(string $path): string
    {
        return sprintf('%s:%d%s', $this->host, $this->port, $path);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    public function initializeCollection(): bool
    {
        $this->logger->info('Initializing Zilliz collection', [
            'collection_name' => $this->collectionName,
            'dimension' => $this->dimension,
        ]);

        try {
            $response = $this->httpClient->request('GET', $this->endpoint('/v1/vector/collections'), [
                'headers' => $this->headers(),
            ]);
            $data = $response->toArray(false);
            $collections = $data['data'] ?? [];

            if (in_array($this->collectionName, $collections, true)) {
                $this->logger->info('Collection already exists', ['collection_name' => $this->collectionName]);
                return true;
            }

            $this->logger->info('Collection does not exist, creating new collection');
            return $this->createCollection($this->dimension);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to initialize collection', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function createCollection(int $dimension): bool
    {
        $this->logger->info('Creating Zilliz collection', [
            'collection_name' => $this->collectionName,
            'dimension' => $dimension,
        ]);

        $payload = [
            'collectionName' => $this->collectionName,
            'dimension' => $dimension,
            'metricType' => 'COSINE',
            'primaryField' => 'id',
            'vectorField' => 'vector',
        ];

        try {
            $response = $this->httpClient->request('POST', $this->endpoint('/v1/vector/collections'), [
                'headers' => $this->headers(),
                'json' => $payload,
            ]);

            if ($response->getStatusCode() < 300) {
                $this->logger->info('Successfully created Zilliz collection');
                return true;
            }

            $this->logger->error('Unexpected status creating collection', ['status_code' => $response->getStatusCode()]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create Zilliz collection', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @param array<int, Product> $products
     */
    public function insertProducts(array $products): bool
    {
        $this->logger->info('Inserting products into Zilliz', [
            'collection_name' => $this->collectionName,
            'product_count' => count($products),
        ]);

        $inserted = 0;
        $skipped = 0;

        foreach ($products as $product) {
            $id = $product->getId();
            $vector = $product->getEmbeddings();

            if (!$id) {
                $this->logger->warning('Skipping product due to missing ID');
                $skipped++;
                continue;
            }
            if (empty($vector)) {
                $this->logger->warning('Skipping product due to missing embeddings', ['product_id' => $id]);
                $skipped++;
                continue;
            }

            $payload = [
                'data' => [
                    [
                        'id' => $id,
                        'title' => $product->getName(),
                        'vector' => $vector,
                    ],
                ],
            ];

            try {
                $response = $this->httpClient->request('POST', $this->endpoint('/v1/vector/collections/' . $this->collectionName . '/data'), [
                    'headers' => $this->headers(),
                    'json' => $payload,
                ]);

                if ($response->getStatusCode() < 300) {
                    $inserted++;
                } else {
                    $this->logger->error('Failed to insert product', ['status_code' => $response->getStatusCode()]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed during product insertion', ['error' => $e->getMessage()]);
                return false;
            }
        }

        $this->logger->info('Finished inserting products', [
            'inserted_count' => $inserted,
            'skipped_count' => $skipped,
        ]);

        return true;
    }

    public function insertProductChunks(Product $product, array $chunks): bool
    {
        $this->logger->info('Inserting product chunks', [
            'collection_name' => $this->collectionName,
            'product_id' => $product->getId(),
            'chunk_count' => count($chunks),
        ]);

        foreach ($chunks as $chunk) {
            if (empty($chunk['vector'])) {
                continue;
            }

            $payload = [
                'data' => [
                    [
                        'product_id' => $product->getId(),
                        'title' => $product->getName(),
                        'vector' => $chunk['vector'],
                        'type' => $chunk['type'] ?? 'generic',
                    ],
                ],
            ];

            try {
                $response = $this->httpClient->request('POST', $this->endpoint('/v1/vector/collections/' . $this->collectionName . '/data'), [
                    'headers' => $this->headers(),
                    'json' => $payload,
                ]);

                if ($response->getStatusCode() >= 300) {
                    $this->logger->error('Failed to insert product chunk', ['status_code' => $response->getStatusCode()]);
                    return false;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed during product chunk insertion', ['error' => $e->getMessage()]);
                return false;
            }
        }

        return true;
    }

    public function searchSimilarProducts(array $embedding, int $limit = 5): array
    {
        $this->logger->info('Searching similar products', [
            'collection_name' => $this->collectionName,
            'limit' => $limit,
        ]);

        if (empty($embedding)) {
            $this->logger->warning('Search embedding is empty');
            return [];
        }

        $payload = [
            'vector' => $embedding,
            'limit' => $limit,
            'outputFields' => ['id', 'title'],
        ];

        try {
            $response = $this->httpClient->request('POST', $this->endpoint('/v1/vector/collections/' . $this->collectionName . '/search'), [
                'headers' => $this->headers(),
                'json' => $payload,
            ]);

            $data = $response->toArray(false);
            $results = $data['data'] ?? [];

            $this->logger->info('Search results retrieved', ['result_count' => count($results)]);
            return $results;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to search for similar products', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function dropCollection(): bool
    {
        $this->logger->info('Dropping Zilliz collection', ['collection_name' => $this->collectionName]);

        try {
            $response = $this->httpClient->request('DELETE', $this->endpoint('/v1/vector/collections/' . $this->collectionName), [
                'headers' => $this->headers(),
            ]);

            if ($response->getStatusCode() < 300) {
                $this->logger->info('Successfully dropped Zilliz collection');
                return true;
            }

            $this->logger->error('Unexpected status dropping collection', ['status_code' => $response->getStatusCode()]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to drop Zilliz collection', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function setDimension(int $dimension): void
    {
        $this->logger->info('Setting vector dimension', ['old' => $this->dimension, 'new' => $dimension]);
        $this->dimension = $dimension;
    }
}
