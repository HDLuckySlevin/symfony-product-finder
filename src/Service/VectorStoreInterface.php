<?php

namespace App\Service;

use App\Entity\Product;

/**
 * Interface for vector database storage services
 * 
 * This interface defines methods for storing and retrieving product data
 * in a vector database for similarity search. It handles collection initialization,
 * product insertion, and similarity search operations.
 */
interface VectorStoreInterface
{
    /**
     * Initialize the vector database collection
     * 
     * @return bool True if the collection exists or was created successfully, false otherwise
     */
    public function initializeCollection(): bool;
    
    /**
     * Create a new collection in the vector database
     * 
     * @param int $dimension The dimension of the vector embeddings
     * @return bool True if the collection was created successfully, false otherwise
     */
    public function createCollection(int $dimension): bool;
    
    /**
     * Insert multiple products into the vector database
     *
     * @param array<int, Product> $products Array of Product objects to insert
     * @return bool True if all insertions were successful, false if any failed
     */
    public function insertProducts(array $products): bool;

    /**
     * Insert all chunk embeddings of a single product.
     *
     * @param Product $product The product being processed
     * @param array<int, array{vector: array<int, float>, type: string}> $chunks
     *        Embedding data for the product
     * @return bool True on success, false on failure
     */
    public function insertProductChunks(Product $product, array $chunks): bool;

    /**
     * Search for products similar to the provided query embedding
     *
     * @param array<int, float> $queryEmbedding The embedding vector to search with
     * @param int $limit Maximum number of results to return
     * @return array<int, mixed> Array of search results, each containing product information
     */
    public function searchSimilarProducts(array $queryEmbedding, int $limit = 5): array;

    /**
     * Drop the current collection from the vector store.
     */
    public function dropCollection(): bool;
}