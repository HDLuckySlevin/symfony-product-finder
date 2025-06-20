<?php

namespace App\Service;

use App\Entity\Product;
use HelgeSverre\Milvus\Milvus as MilvusClient;
use Psr\Log\LoggerInterface;

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
     * Base name for collections in the vector database
     */
    private string $collectionNameBase;
    private string $textCollectionName;
    private string $imageCollectionName;

    /**
     * Dimension of the text vector embeddings
     */
    private int $textDimension;
    /**
     * Dimension of the image vector embeddings
     */
    private int $imageDimension;

    /**
     * Logger for recording operations and errors
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     * 
     * @param MilvusClient $milvus The Milvus client instance
     * @param LoggerInterface $logger The logger service
     * @param string $collectionName The name of the collection to use (default: 'default')
     * @param int $dimension The dimension of the vector embeddings (default: 1536)
     */
    public function __construct(
        MilvusClient $milvus,
        LoggerInterface $logger,
        string $collectionNameBase = 'default',
        int $textDimension = 768, // Default assuming common text model size
        int $imageDimension = 512 // Default assuming common image model size
    ) {
        $this->milvus = $milvus;
        $this->logger = $logger;
        $this->collectionNameBase = $collectionNameBase;
        $this->textCollectionName = $collectionNameBase . '_text';
        $this->imageCollectionName = $collectionNameBase . '_image';
        $this->textDimension = $textDimension;
        $this->imageDimension = $imageDimension;
    }

    /**
     * Initialize the vector database collection
     * 
     * Checks if the collection exists and returns true if it does.
     * If the collection doesn't exist, it calls createCollection to create it.
     * 
     * @return bool True if the collection exists or was created successfully, false otherwise
     */
    public function initializeCollection(): bool // Keep signature for interface compatibility for now
    {
        return $this->initializeCollections();
    }

    private function initializeCollections(): bool
    {
        $this->logger->info('Initializing Milvus collections', [
            'base_name' => $this->collectionNameBase,
            'text_collection' => $this->textCollectionName,
            'text_dimension' => $this->textDimension,
            'image_collection' => $this->imageCollectionName,
            'image_dimension' => $this->imageDimension,
        ]);

        try {
            $successText = $this->createCollectionIfNotExists(
                $this->textCollectionName,
                $this->textDimension,
                'text_vector'
            );
            $successImage = $this->createCollectionIfNotExists(
                $this->imageCollectionName,
                $this->imageDimension,
                'image_vector'
            );
            return $successText && $successImage;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to initialize one or more Milvus collections', [
                'base_name' => $this->collectionNameBase,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * LEGACY: Creates the text collection with the given dimension.
     * Prefer initializeCollection() for creating both text and image collections
     * with dimensions configured in the service.
     *
     * @param int $dimension The dimension for the text vector embeddings.
     * @return bool True if the text collection was created successfully or already existed, false otherwise.
     */
    public function createCollection(int $dimension): bool
    {
        $this->logger->warning রবিLEGACY METHOD CALLED: createCollection(int $dimension). Prefer initializeCollection() for robust setup. Attempting to create/check text collection '{$this->textCollectionName}' with dimension {$dimension}. The configured text dimension is {$this->textDimension}. Consider if this call is appropriate.);
        // We use the provided dimension for this legacy call, even if it differs from configured textDimension.
        return $this->createCollectionIfNotExists($this->textCollectionName, $dimension, 'text_vector');
    }

    /**
     * Creates a collection if it doesn't exist.
     *
     * @param string $collectionName
     * @param integer $dimension
     * @param string $vectorFieldName
     * @return boolean
     */
    private function createCollectionIfNotExists(string $collectionName, int $dimension, string $vectorFieldName): bool
    {
        $collections = $this->milvus->collections()->list()->json()['data'] ?? [];
        if (in_array($collectionName, $collections)) {
            $this->logger->info('Collection already exists', ['collection_name' => $collectionName]);
            return true;
        }

        $this->logger->info('Creating new Milvus collection', [
            'collection_name' => $collectionName,
            'dimension' => $dimension,
            'vector_field_name' => $vectorFieldName,
            'metric_type' => 'COSINE',
            'primary_field' => 'product_id' // Using 'product_id' for clarity
        ]);

        try {
            // The Milvus PHP library's create method might be too simple.
            // It expects collectionName, dimension, metricType, primaryField, vectorField.
            // We need to ensure it creates an ID field that is an INT64, and is the primary key.
            // And a vector field that is FloatVector of the specified dimension.
            // The library might default primary key to 'id' of type INT64 and autoId=true.
            // Let's assume 'id' is the primary key name used by the library by default for the primary field.
            // And 'vector' for the vector field name if not specified.
            // The library's `create` method has:
            // collectionName, dimension, metricType = "L2", primaryField = "id", vectorField = "vector", autoId = true, description = ""
            // So we can override primaryField and vectorField.
            $this->milvus->collections()->create(
                collectionName: $collectionName,
                dimension: $dimension,
                metricType: "COSINE", // Good for semantic similarity
                primaryField: "product_id", // This will be our product's own ID
                vectorField: $vectorFieldName,
                autoId: false // We will provide the product_id
            );
            // We might need to create an index explicitly after creation for performance.
            // $this->milvus->indexes()->create($collectionName, $vectorFieldName, 'IVF_FLAT', 'L2', ['nlist' => 128]);
            // For now, focusing on creation. Indexing is an optimization/requirement for search.

            $this->logger->info('Successfully created Milvus collection', ['collection_name' => $collectionName]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create Milvus collection', [
                'collection_name' => $collectionName,
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
        $this->logger->info('Inserting products into Milvus text and image collections', [
            'text_collection' => $this->textCollectionName,
            'image_collection' => $this->imageCollectionName,
            'product_count' => count($products)
        ]);

        $insertedTextCount = 0;
        $insertedImageCount = 0;
        $skippedTextCount = 0;
        $skippedImageCount = 0;
        $totalProcessed = 0;

        try {
            foreach ($products as $product) {
                $totalProcessed++;
                $productId = $product->getId();
                $productName = $product->getName() ?: 'unknown';

                if (!$productId) {
                    $this->logger->warning('Skipping product due to missing product ID.', ['product_name' => $productName]);
                    $skippedTextCount++;
                    $skippedImageCount++;
                    continue;
                }

                // Insert text embeddings
                $textEmbeddings = $product->getEmbeddings();
                if (!empty($textEmbeddings)) {
                    $this->logger->debug('Inserting text embedding into Milvus', [
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'collection_name' => $this->textCollectionName,
                        'embedding_size' => count($textEmbeddings)
                    ]);
                    $this->milvus->vector()->insert(
                        collectionName: $this->textCollectionName,
                        data: [
                            'product_id' => $productId, // Ensure this matches primaryField
                            'title' => $productName, // Store title for potential retrieval
                            'text_vector' => $textEmbeddings,
                        ]
                        // dbName parameter might not be needed if using default DB or if client is configured
                    );
                    $insertedTextCount++;
                } else {
                    $this->logger->warning('Skipping text embedding for product due to missing embeddings', [
                        'product_id' => $productId, 'product_name' => $productName
                    ]);
                    $skippedTextCount++;
                }

                // Insert image embeddings
                $imageEmbeddings = $product->getImageEmbeddings();
                if (!empty($imageEmbeddings)) {
                    $this->logger->debug('Inserting image embedding into Milvus', [
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'collection_name' => $this->imageCollectionName,
                        'embedding_size' => count($imageEmbeddings)
                    ]);
                    $this->milvus->vector()->insert(
                        collectionName: $this->imageCollectionName,
                        data: [
                            'product_id' => $productId, // Ensure this matches primaryField
                            'title' => $productName, // Store title for potential retrieval
                            'image_vector' => $imageEmbeddings,
                        ]
                    );
                    $insertedImageCount++;
                } else {
                    // This is not necessarily a warning if a product simply has no image
                    $this->logger->info('No image embedding to insert for product', [
                        'product_id' => $productId, 'product_name' => $productName
                    ]);
                    $skippedImageCount++;
                }
            }

            $this->logger->info('Finished inserting products into Milvus collections', [
                'processed_products' => $totalProcessed,
                'text_collection' => $this->textCollectionName,
                'inserted_text_count' => $insertedTextCount,
                'skipped_text_count' => $skippedTextCount,
                'image_collection' => $this->imageCollectionName,
                'inserted_image_count' => $insertedImageCount,
                'skipped_image_count' => $skippedImageCount,
            ]);

            return true; // Consider success if no exceptions, even if some were skipped.
        } catch (\Throwable $e) {
            $this->logger->error('Failed during product insertion into Milvus collections', [
                'base_collection_name' => $this->collectionNameBase,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'inserted_before_error' => $insertedCount
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
        $this->logger->info('Searching for similar products in Milvus text collection', [
            'text_collection' => $this->textCollectionName,
            'embedding_size' => count($queryEmbedding),
            'limit' => $limit
        ]);

        if (empty($queryEmbedding)) {
            $this->logger->warning('Search query embedding is empty, returning no results.');
            return [];
        }

        try {
            $result = $this->milvus->vector()->search(
                collectionName: $this->textCollectionName,
                vector: $queryEmbedding,
                vectorField: 'text_vector', // Specify which vector field to search
                limit: $limit,
                outputFields: ["product_id", "title"] // Ensure these fields exist in the collection
                // dbName: $this->textCollectionName, // Might not be needed
            );

            $data = $result->json()['data'] ?? [];
            $resultCount = count($data);

            $this->logger->info('Successfully retrieved similar products from Milvus text collection', [
                'text_collection' => $this->textCollectionName,
                'result_count' => $resultCount
            ]);

            if ($resultCount === 0) {
                $this->logger->warning('No similar products found in Milvus text collection', [
                    'text_collection' => $this->textCollectionName
                ]);
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to search for similar products in Milvus text collection', [
                'text_collection' => $this->textCollectionName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
}
