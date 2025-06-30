<?php

namespace App\Service;

use App\Entity\Product;
use HelgeSverre\Milvus\Milvus as MilvusClient;
use Psr\Log\LoggerInterface;
use App\Service\PythonEmbeddingService;

/**
 * Service for interacting with Milvus vector database
 * 
 * This service provides methods for storing and retrieving product data
 * in a vector database for similarity search. It handles collection initialization,
 * product insertion, and similarity search operations.
 */
class MilvusVectorStoreService implements VectorStoreInterface
{
    /**
     * Milvus client instance for interacting with the vector database
     */
    private MilvusClient $milvus;

    /**
     * Name of the collection in the vector database
     */
    private string $collectionName;

    /**
     * Embedding service for dimension and embedding calls
     */
    private PythonEmbeddingService $embeddingService;

    /**
     * Dimension of the vector embeddings
     */
    private int $dimension;

    /**
     * Logger for recording operations and errors
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     * 
     * @param MilvusClient $milvus   The Milvus client instance
     * @param LoggerInterface $logger The logger service
     * @param PythonEmbeddingService $embeddingService Service for embedding API calls
     * @param string $collectionName The name of the collection to use (default: 'default')
     */
    public function __construct(
        MilvusClient $milvus,
        LoggerInterface $logger,
        PythonEmbeddingService $embeddingService,
        string $collectionName = 'default'
    ) {
        $this->milvus = $milvus;
        $this->logger = $logger;
        $this->embeddingService = $embeddingService;
        $this->collectionName = $collectionName;
        $this->dimension = $this->embeddingService->getVectorDimension();
    }

    /**
     * Initialize the vector database collection
     * 
     * Checks if the collection exists and returns true if it does.
     * If the collection doesn't exist, it calls createCollection to create it.
     * 
     * @return bool True if the collection exists or was created successfully, false otherwise
     */
    public function initializeCollection(): bool
    {
        $this->logger->info('Initializing Milvus collection', [
            'collection_name' => $this->collectionName,
            'dimension' => $this->dimension
        ]);

        try {
            $collections = $this->milvus->collections()->list()->json()['data'] ?? [];
            if (in_array($this->collectionName, $collections)) {
                $this->logger->info('Collection already exists', [
                    'collection_name' => $this->collectionName
                ]);
                return true;
            }

            $this->logger->info('Collection does not exist, creating new collection', [
                'collection_name' => $this->collectionName
            ]);
            // Pass the configured dimension of the service to createCollection.
            return $this->createCollection($this->dimension);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to initialize collection', [
                'collection_name' => $this->collectionName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Create a new collection in the vector database
     *
     * Creates a collection with a primary key field (product_id) and a Float_vector field.
     *
     * @param int $dimension The dimension of the vector embeddings
     * @return bool True if the collection was created successfully, false otherwise
     */
    public function createCollection(int $dimension): bool
    {
        $this->logger->info('Creating new Milvus collection', [
            'collection_name' => $this->collectionName,
            'dimension' => $dimension,
            'metric_type' => 'COSINE',
            'primary_field' => 'id',
            'vector_field' => 'vector' // Default vector field name
        ]);

        try {
            $this->milvus->collections()->create(
                collectionName: $this->collectionName,
                dimension: $dimension,
                metricType: "COSINE",
                primaryField: "id", // Using product's own ID
                vectorField: "vector"    // Standard vector field name
            );

            $this->logger->info('Successfully created Milvus collection', [
                'collection_name' => $this->collectionName
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create Milvus collection', [
                'collection_name' => $this->collectionName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Insert multiple products into the vector database
     * 
     * Iterates through the provided products array and inserts each valid product
     * with embeddings into the vector database.
     * 
     * @param array<int, Product> $products Array of Product objects to insert
     * @return bool True if all insertions were successful, false if any failed
     */
    public function insertProducts(array $products): bool
    {
        $this->logger->info('Inserting products into Milvus collection', [
            'collection_name' => $this->collectionName,
            'product_count' => count($products)
        ]);

        $insertedCount = 0;
        $skippedCount = 0;

        try {
            foreach ($products as $product) {

                $productId = $product->getId();
                $productName = $product->getName() ?: 'unknown';
                $embeddings = $product->getEmbeddings(); // Only text embeddings now

                if (!$productId) {
                    $this->logger->warning('Skipping product due to missing product ID.', ['product_name' => $productName]);
                    $skippedCount++;
                    continue;
                }

                if (empty($embeddings)) {
                    $this->logger->warning('Skipping product due to missing text embeddings', [
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'embedding_size' => count($product->getEmbeddings())
                    ]);
                    $skippedCount++;
                    continue;
                }
                $this->milvus->vector()->insert(
                    collectionName: $this->collectionName,
                    data: [
                        'title' => $product->getName(),
                        'vector' => $product->getEmbeddings(),
                        'type' => 'product',
                    ]
                );
                $insertedCount++;
            }

            $this->logger->info('Finished inserting products into Milvus collection', [
                'collection_name' => $this->collectionName,
                'inserted_count' => $insertedCount,
                'skipped_count' => $skippedCount,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed during product insertion into Milvus collection', [
                'collection_name' => $this->collectionName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'inserted_before_error' => $insertedCount
            ]);
            return false;
        }
    }

    /**
     * Insert all chunk embeddings of a single product into the collection.
     *
     * @param Product $product
     * @param array<int, array{vector: array<int, float>, type: string}> $chunks
     * @return bool
     */
    public function insertProductChunks(Product $product, array $chunks): bool
    {
        $productId = $product->getId();
        $productName = $product->getName() ?: 'unknown';

        if (!$productId) {
            $this->logger->warning('Skipping product due to missing product ID.', [
                'product_name' => $productName
            ]);
            return false;
        }

        $inserted = 0;

        try {
            foreach ($chunks as $chunk) {
                if (empty($chunk['vector'])) {
                    continue;
                }

                $this->milvus->vector()->insert(
                    collectionName: $this->collectionName,
                    data: [
                        'product_id' => $productId,
                        'title' => $productName,
                        'vector' => $chunk['vector'],
                        'type' => $chunk['type'] ?? 'generic',
                    ]
                );

                $inserted++;
            }

            $this->logger->info('Inserted product chunks into Milvus collection', [
                'collection_name' => $this->collectionName,
                'product_id' => $productId,
                'chunk_count' => $inserted
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed during product chunk insertion', [
                'collection_name' => $this->collectionName,
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'inserted_before_error' => $inserted
            ]);
            return false;
        }
    }

    /**
     * Search for products similar to the provided query embedding
     * 
     * Performs a vector similarity search in the database using the provided
     * query embedding vector.
     * 
     * @param array<int, float> $queryEmbedding The embedding vector to search with
     * @param int $limit Maximum number of results to return (default: 5)
     * @return array<int, mixed> Array of search results, each containing product information
     */
    public function searchSimilarProducts(array $queryEmbedding, int $limit = 5): array
    {
        $this->logger->info('Searching for similar products in Milvus collection', [
            'collection_name' => $this->collectionName, // Corrected log key
            'embedding_size' => count($queryEmbedding),
            'limit' => $limit,
            'embed' =>$queryEmbedding
        ]);

        if (empty($queryEmbedding)) {
            $this->logger->warning('Search query embedding is empty, returning no results.');
            return [];
        }

        try {
            $result = $this->milvus->vector()->search(
                collectionName: $this->collectionName, // Corrected collection name
                vector: $queryEmbedding,
                limit: $limit,
                outputFields: ["id", "title"],
                dbName: $this->collectionName
            );

            $data = $result->json()['data'] ?? [];
            $resultCount = count($data);

            $this->logger->info('Successfully retrieved similar products from Milvus collection', [
                'collection_name' => $this->collectionName, // Corrected log key
                'result_count' => $resultCount
            ]);

            if ($resultCount === 0) {
                $this->logger->warning('No similar products found in Milvus collection', [
                    'collection_name' => $this->collectionName, // Corrected log key
                    'infos' => $data
                ]);
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to search for similar products in Milvus collection', [
                'collection_name' => $this->collectionName, // Corrected log key
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Drop the configured collection from Milvus.
     */
    public function dropCollection(): bool
    {
        $this->logger->info('Dropping Milvus collection', [
            'collection_name' => $this->collectionName,
        ]);

        try {
            $this->milvus->collections()->drop(collectionName: $this->collectionName);
            $this->logger->info('Successfully dropped Milvus collection', [
                'collection_name' => $this->collectionName,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to drop Milvus collection', [
                'collection_name' => $this->collectionName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Update the vector dimension used when creating collections.
     */
    public function setDimension(int $dimension): void
    {
        $this->logger->info('Updating vector dimension', ['old' => $this->dimension, 'new' => $dimension]);
        $this->dimension = $dimension;
    }
}

