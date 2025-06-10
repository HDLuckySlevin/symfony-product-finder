<?php

namespace App\Service;

use App\Entity\Product;
use HelgeSverre\Milvus\Milvus as MilvusClient;

/**
 * Service for interacting with Zilliz/Milvus vector database
 * 
 * This service provides methods for storing and retrieving product data
 * in a vector database for similarity search. It handles collection initialization,
 * product insertion, and similarity search operations.
 */
class ZillizVectorDBService
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
     * @param string $collectionName The name of the collection to use (default: 'products')
     * @param int $dimension The dimension of the vector embeddings (default: 1536)
     */
    public function __construct(
        MilvusClient $milvus,
        string $collectionName, // Default removed
        int $dimension = 1536
    ) {
        if (empty(trim($collectionName))) {
            throw new \InvalidArgumentException('Milvus collection name environment variable (MILVUS_COLLECTION) cannot be empty.');
        }
        $this->milvus = $milvus;
        $this->collectionName = $collectionName;
        $this->dimension = $dimension;
    }

    /**
     * Initialize the vector database collection
     * 
     * Checks if the collection exists and returns true if it does.
     * Currently, collection creation is commented out.
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

            // $this->milvus->createCollection([
            //     'collection_name' => $this->collectionName,
            //     'fields' => [
            //         [
            //             'name' => 'id',
            //             'description' => 'ID',
            //             'data_type' => 'INT64',
            //             'is_primary_key' => true,
            //             'auto_id' => false,
            //         ],
            //         [
            //             'name' => 'product_name',
            //             'description' => 'Product Name',
            //             'data_type' => 'VARCHAR',
            //             'max_length' => 255,
            //         ],
            //         [
            //             'name' => 'embedding',
            //             'description' => 'Embedding',
            //             'data_type' => 'FLOAT_VECTOR',
            //             'type_params' => [
            //                 'dim' => $this->dimension,
            //             ],
            //         ],
            //     ],
            // ]);
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Insert a single product into the vector database
     * 
     * Note: This method contains debug code (dump statements) and uses hardcoded
     * vector values instead of the product's actual embeddings.
     * 
     * @param Product $product The product to insert
     * @return bool True if the insertion was successful, false otherwise
     */
    public function insertProduct(Product $product): bool
    {
        dump('Test');

        dump($product->getName());
        dump($product->getEmbeddings());
        try {
            $this->milvus->vector()->insert(
                collectionName: $this->collectionName,
                data: [
                    'vector' => [0.1, 0.2, 0.3 /* etc... */],
                    "title" => $product->getName(),
                    "link" => "https://example.com/document-name-here",
                ]
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
                        'primary_key' => $product->getId(),
                        'title' => $product->getName(),
                        'vector' => $product->getEmbeddings(),
                        'type' => 'product',
                    ]
                );
            }

            return true;
        } catch (\Throwable $e) {
            dump($e->getMessage());
            return false;
        }
    }

    /**
     * Insert product features into the vector database
     * 
     * Iterates through the provided product's features and inserts each feature
     * with its embedding into the vector database.
     * 
     * @param Product $product The product whose features to insert
     * @param array<string, array<int, float>> $featureEmbeddings Array of feature embeddings
     * @return bool True if all insertions were successful, false if any failed
     */
    public function insertProductFeatures(Product $product, array $featureEmbeddings): bool
    {
        try {
            $features = $product->getFeatures();
            if (empty($features) || empty($featureEmbeddings)) {
                return true;
            }

            foreach ($features as $index => $feature) {
                if (!isset($featureEmbeddings[$feature])) {
                    continue;
                }

                $this->milvus->vector()->insert(
                    collectionName: $this->collectionName,
                    data: [
                        'primary_key' => $product->getId() * 10000 + $index,
                        'title' => $feature,
                        'vector' => $featureEmbeddings[$feature],
                        'product_id' => $product->getId(),
                        'product_name' => $product->getName(),
                        'type' => 'feature',
                    ]
                );
            }

            return true;
        } catch (\Throwable $e) {
            dump($e->getMessage());
            return false;
        }
    }

    /**
     * Insert product specifications into the vector database
     * 
     * Iterates through the provided product's specifications and inserts each specification
     * with its embedding into the vector database.
     * 
     * @param Product $product The product whose specifications to insert
     * @param array<string, array<int, float>> $specificationEmbeddings Array of specification embeddings
     * @return bool True if all insertions were successful, false if any failed
     */
    public function insertProductSpecifications(Product $product, array $specificationEmbeddings): bool
    {
        try {
            $specifications = $product->getSpecifications();
            if (empty($specifications) || empty($specificationEmbeddings)) {
                return true;
            }

            $index = 0;
            foreach ($specifications as $key => $value) {
                $specText = $key . ': ' . $value;
                if (!isset($specificationEmbeddings[$specText])) {
                    continue;
                }

                $this->milvus->vector()->insert(
                    collectionName: $this->collectionName,
                    data: [
                        'primary_key' => $product->getId() * 10000 + 5000 + $index,
                        'title' => $specText,
                        'vector' => $specificationEmbeddings[$specText],
                        'product_id' => $product->getId(),
                        'product_name' => $product->getName(),
                        'type' => 'specification',
                    ]
                );
                $index++;
            }

            return true;
        } catch (\Throwable $e) {
            dump($e->getMessage());
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
                outputFields: ["primary_key", "title", "link"],
                limit: 5,
            );

            return $result->json()['data'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
