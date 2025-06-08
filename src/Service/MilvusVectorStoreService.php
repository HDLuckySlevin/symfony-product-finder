<?php

namespace App\Service;

use App\Entity\Product;
use HelgeSverre\Milvus\Milvus as MilvusClient;

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
     * Dimension of the vector embeddings
     */
    private int $dimension;

    /**
     * Constructor
     * 
     * @param MilvusClient $milvus The Milvus client instance
     * @param string $collectionName The name of the collection to use (default: 'default')
     * @param int $dimension The dimension of the vector embeddings (default: 1536)
     */
    public function __construct(
        MilvusClient $milvus,
        string $collectionName = 'default',
        int $dimension = 1536
    ) {
        $this->milvus = $milvus;
        $this->collectionName = $collectionName;
        $this->dimension = $dimension;
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
        try {
            $collections = $this->milvus->collections()->list()->json()['data'];
            if (in_array($this->collectionName, $collections)) {
                return true;
            }

            return $this->createCollection($this->dimension);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Create a new collection in the vector database
     * 
     * Creates a collection with an id field (primary key), auto_id parameter,
     * and a Float_vector field with the specified dimension.
     * 
     * @param int $dimension The dimension of the vector embeddings
     * @return bool True if the collection was created successfully, false otherwise
     */
    public function createCollection(int $dimension): bool
    {
        try {
            $this->milvus->collections()->create(
                collectionName: $this->collectionName,
                dimension: $dimension,
                metricType: "COSINE",
                primaryField: "id",
                vectorField: "vector"
            );
            return true;
        } catch (\Throwable $e) {
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
        try {
            foreach ($products as $product) {
                if (!$product instanceof Product || empty($product->getEmbeddings())) {
                    continue;
                }

                $this->milvus->vector()->insert(
                    collectionName: $this->collectionName,
                    data: [
                        'title' => $product->getName(),
                        'vector' => $product->getEmbeddings(),
                        'type' => 'product',
                    ],
                    dbName: $this->collectionName,
                );
            }

            return true;
        } catch (\Throwable $e) {
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
        try {
            $result = $this->milvus->vector()->search(    
                collectionName: $this->collectionName,
                vector: $queryEmbedding,
                limit: $limit,
                outputFields: ["id", "title"],
                dbName: $this->collectionName,
            );
            return $result->json()['data'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
