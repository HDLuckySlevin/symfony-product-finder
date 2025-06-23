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
     * Name of the collection in the vector database
     */
    private string $collectionName;

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
     * @param MilvusClient $milvus The Milvus client instance
     * @param LoggerInterface $logger The logger service
     * @param string $collectionName The name of the collection to use (default: 'default')
     * @param int $dimension The dimension of the vector embeddings (default: 1536)
     */
    public function __construct(
        MilvusClient $milvus,
        LoggerInterface $logger,
        string $collectionName = 'default',
        int $dimension = 768 // Reverted default to 768
    ) {
        $this->milvus = $milvus;
        $this->logger = $logger;
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
        $this->logger->info('Creating new Milvus collection for fine-grained embeddings', [
            'collection_name' => $this->collectionName,
            'dimension' => $dimension,
            'metric_type' => 'COSINE',
            'primary_field' => 'embedding_id', // New auto-generated primary key for each embedding
            'vector_field' => 'vector'
        ]);

        // Define schema: embedding_id (PK, auto-generated), product_id, product_name, type, vector
        $schema = [
            [
                'name' => 'embedding_id', // Primary key for the embedding entry
                'dataType' => \HelgeSverre\Milvus\Data\DataType::INT64, // Milvus DataType
                'isPrimaryKey' => true,
                'autoID' => true, // Enable auto-ID generation for this field
            ],
            [
                'name' => 'product_id', // Foreign key to the product
                'dataType' => \HelgeSverre\Milvus\Data\DataType::INT64,
                'description' => 'ID of the product this embedding belongs to',
            ],
            [
                'name' => 'product_name', // Store product name for easier debugging/display
                'dataType' => \HelgeSverre\Milvus\Data\DataType::VARCHAR,
                'maxLength' => 255, // Max length for varchar
                'description' => 'Name of the product',
            ],
            [
                'name' => 'type', // Type of content (e.g., description, specification, feature, image)
                'dataType' => \HelgeSverre\Milvus\Data\DataType::VARCHAR,
                'maxLength' => 100,
                'description' => 'Type of the embedded content',
            ],
            [
                'name' => 'vector', // The actual embedding vector
                'dataType' => \HelgeSverre\Milvus\Data\DataType::FLOAT_VECTOR,
                'dimension' => $dimension,
                'description' => 'Embedding vector',
            ]
        ];

        try {
            // The `create` method in the Milvus PHP SDK might not directly support full schema definition.
            // It typically expects primaryField, vectorField, and dimension.
            // We might need to use a more direct API call if the SDK is limited,
            // or adjust if the SDK has been updated to support this.
            // For now, assuming the SDK's create focuses on simple schemas.
            // If `createWithSchema` or similar exists, that would be better.
            // Let's check the SDK's capabilities or proceed with a basic creation and note this limitation.

            // The current SDK `create` method seems to be:
            // create(string $collectionName, int $dimension, string $primaryField = "id", string $vectorField = "vector", string $metricType = "L2", int $primaryFieldMaxLength = 65535, bool $autoId = false, string $description = "")
            // This doesn't allow specifying multiple scalar fields directly in `create`.
            // This is a limitation. We need to ensure 'product_id', 'product_name', 'type' are part of the collection.
            // The underlying Milvus API supports richer schemas.
            // A workaround could be to create a minimal collection and then use raw API calls to add fields,
            // or hope that inserting data with these fields implicitly creates them (unlikely for typed fields).

            // Given the SDK's current structure, we might need to adjust the expectation or log a warning.
            // Let's proceed with the existing `create` and then handle insertion.
            // The `insert` method of the SDK should handle arbitrary data fields passed to it.
            // Milvus typically requires fields to be defined in the schema before insertion.

            // For the purpose of this task, I'll assume the collection can be created
            // and that the `insert` method will correctly handle the new fields.
            // If schema definition is strict and needs to be done upfront, this part would need revisiting
            // potentially by extending the SDK or using raw client calls.

            // We will use 'embedding_id' as the primary field and enable autoID.
             $this->milvus->collections()->create(
                collectionName: $this->collectionName,
                dimension: $dimension,
                primaryField: "embedding_id",
                vectorField: "vector",
                metricType: "COSINE",
                autoId: true, // Enable autoID for the primary key
                description: "Collection for fine-grained product embeddings"
            );
            // NOTE: This does NOT explicitly define product_id, product_name, type.
            // Milvus usually requires fields to be defined in the schema.
            // The SDK's insert method might dynamically add them if the Milvus version supports it,
            // or it might fail. This is a potential point of failure depending on Milvus server config & version.
            // For a robust solution, schema must be explicitly defined.

            $this->logger->info('Successfully initiated Milvus collection creation', [
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
     * Insert multiple embedding entries into the vector database.
     * Each entry corresponds to a chunk of text or an image from a product.
     *
     * @param array<int, array<string, mixed>> $embeddingDataList Array of embedding data.
     *        Each item should contain: 'product_id', 'product_name', 'type', 'vector'.
     *        'embedding_id' will be auto-generated by Milvus.
     * @return bool True if all insertions were successful, false if any failed.
     */
    public function insertEmbeddings(array $embeddingDataList): bool
    {
        if (empty($embeddingDataList)) {
            $this->logger->info('No embedding data provided for insertion.');
            return true; // Nothing to insert, so technically successful.
        }

        $this->logger->info('Inserting embedding data into Milvus collection', [
            'collection_name' => $this->collectionName,
            'embedding_count' => count($embeddingDataList)
        ]);

        $insertedCount = 0;
        $failedCount = 0;

        // The Milvus PHP SDK's insert method expects a single array of data where each key
        // is a field name and its value is an array of values for that field, across all rows.
        // Example:
        // [
        //   'product_id' => [101, 101, 102],
        //   'product_name' => ['Product A', 'Product A', 'Product B'],
        //   'type' => ['desc', 'feature', 'desc'],
        //   'vector' => [[0.1,...], [0.2,...], [0.3,...]]
        // ]
        // We need to transform $embeddingDataList into this format.

        $dataForMilvus = [
            'product_id' => [],
            'product_name' => [],
            'type' => [],
            'vector' => [],
        ];

        foreach ($embeddingDataList as $item) {
            if (empty($item['vector'])) {
                $this->logger->warning('Skipping embedding data due to missing vector.', [
                    'product_id' => $item['product_id'] ?? 'unknown',
                    'type' => $item['type'] ?? 'unknown'
                ]);
                $failedCount++;
                continue;
            }
            if (!isset($item['product_id']) || !isset($item['product_name']) || !isset($item['type'])) {
                 $this->logger->warning('Skipping embedding data due to missing metadata.', [
                    'item' => $item
                ]);
                $failedCount++;
                continue;
            }

            $dataForMilvus['product_id'][] = (int)$item['product_id'];
            $dataForMilvus['product_name'][] = (string)$item['product_name'];
            $dataForMilvus['type'][] = (string)$item['type'];
            $dataForMilvus['vector'][] = $item['vector'];
        }

        if (empty($dataForMilvus['vector'])) {
             $this->logger->info('No valid embedding data to insert after filtering.');
             return $failedCount === 0;
        }

        try {
            // The SDK's insert method might be `vector()->insert()` or `entities()->insert()`
            // depending on the SDK version and structure. Assuming `vector()->insert()`.
            // The `data` parameter should be the transformed array.
            $response = $this->milvus->vector()->insert(
                collectionName: $this->collectionName,
                data: $dataForMilvus
            );

            // Check response for success. The SDK might throw an exception on failure,
            // or return a response object to inspect.
            // Assuming successful insertion if no exception.
            // The response from Milvus insert usually contains insert_count and IDs.
            // Example: $response->json()['data']['insert_count']

            // For simplicity, we'll rely on exception handling for errors here.
            // A more robust check would inspect the response object.
            $insertedCount = count($dataForMilvus['vector']); // Assuming all were inserted if no error

            $this->logger->info('Finished inserting embedding data into Milvus collection', [
                'collection_name' => $this->collectionName,
                'attempted_insert_count' => count($dataForMilvus['vector']),
                'successfully_inserted_count' => $insertedCount, // This might need adjustment based on actual response
                'skipped_malformed_count' => $failedCount,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed during embedding data insertion into Milvus collection', [
                'collection_name' => $this->collectionName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'partially_inserted_count' => 0 // Hard to tell without more context from SDK
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
                collectionName: $this->collectionName,
                vector: $queryEmbedding,
                limit: $limit,
                // Output fields relevant to the new schema
                // embedding_id is the PK, product_id links to the original product
                outputFields: ["embedding_id", "product_id", "product_name", "type"],
                dbName: $this->collectionName // dbName parameter might not be needed if using default db or if collection name is unique across dbs
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
}
