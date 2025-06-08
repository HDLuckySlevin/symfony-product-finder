<?php

namespace App\Service;

use App\Entity\Product;
use OpenAI\Client;

/**
 * Service for generating vector embeddings using OpenAI's API
 * 
 * This service implements the EmbeddingGeneratorInterface and provides
 * methods for generating vector embeddings for products and search queries
 * using OpenAI's embedding models.
 */
class OpenAIEmbeddingGenerator implements EmbeddingGeneratorInterface
{
    /**
     * OpenAI API client
     */
    private Client $client;

    /**
     * OpenAI embedding model to use
     */
    private string $embeddingModel;

    /**
     * Constructor
     * 
     * @param Client $client The OpenAI API client
     * @param string $embeddingModel The embedding model to use (default: 'text-embedding-ada-002')
     */
    public function __construct(Client $client, string $embeddingModel = 'text-embedding-ada-002')
    {
        $this->client = $client;
        $this->embeddingModel = $embeddingModel;
    }

    /**
     * Generate embeddings for a product
     * 
     * Groups product fields into title, metadata, specifications, and features.
     * Combines all product information into a single text chunk.
     * Generates an embedding for the combined text.
     * 
     * {@inheritdoc}
     * 
     * @param Product $product The product to generate embeddings for
     * @return array<int, float> The embedding vector
     */
    public function generateEmbedding(Product $product): array
    {
        // Group fields into title, metadata, specifications, and features
        $groupedFields = $this->groupFieldData($product);

        // Create a single chunk with all product information
        $chunk = $this->getChunk($groupedFields);

        // Generate embedding for the combined text
        return $this->generateEmbeddingForText($chunk);
    }

    /**
     * Generate embeddings for a search query
     * 
     * Creates a vector embedding for the provided search query text.
     * 
     * {@inheritdoc}
     * 
     * @param string $query The search query text
     * @return array<int, float> The embedding vector
     */
    public function generateQueryEmbedding(string $query): array
    {
        return $this->generateEmbeddingForText($query);
    }

    /**
     * Generate embedding for a text using OpenAI API
     * 
     * Makes an API call to OpenAI's embeddings endpoint to generate a vector
     * representation of the provided text.
     * 
     * @param string $text The text to generate embeddings for
     * @return array<int, float> The embedding vector
     * @throws \RuntimeException If the API response format is invalid or if the API call fails
     */
    private function generateEmbeddingForText(string $text): array
    {
        try {
            // Send the full text directly to the API without chunking
            $response = $this->client->embeddings()->create([
                'model' => $this->embeddingModel,
                'input' => $text,
            ]);

            if (isset($response->embeddings[0]->embedding)) {
                return $response->embeddings[0]->embedding;
            } else {
                throw new \RuntimeException('Failed to generate embedding: Invalid response format from OpenAI client');
            }
        } catch (\Exception $e) {
            // Log the error message for debugging
            // error_log('OpenAI API Error: ' . $e->getMessage());
            // Re-throw the exception
            throw new \RuntimeException('Failed to generate embedding: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Group product fields into title, metadata, specifications, and features
     * 
     * @param Product $product The product to group fields for
     * @return array<string, string> Grouped fields with keys 'title', 'metadata', 'specifications', 'features'
     */
    private function groupFieldData(Product $product): array
    {
        // Title: Product name and brand
        $title = $product->getName() . ' ' . $product->getBrand();

        // Metadata: Category and description
        $metadata = $product->getCategory() . ' ' . $product->getDescription();

        // Specifications
        $specifications = '';
        if (!empty($product->getSpecifications())) {
            foreach ($product->getSpecifications() as $key => $value) {
                $specifications .= ' ' . $key . ': ' . $value;
            }
        }

        // Features
        $features = '';
        if (!empty($product->getFeatures())) {
            $features = implode(', ', $product->getFeatures());
        }

        return [
            'title' => $title,
            'metadata' => $metadata,
            'specifications' => $specifications,
            'features' => $features
        ];
    }

    /**
     * Combine all product information into a single text chunk
     * 
     * @param array<string, string> $groupedFields Grouped fields with keys 'title', 'metadata', 'specifications', and 'features'
     * @return array<int, string> Array containing a single text chunk
     */
    private function getChunk(array $groupedFields): string
    {
        $title = $groupedFields['title'];
        $metadata = $groupedFields['metadata'];
        $specifications = $groupedFields['specifications'];
        $features = $groupedFields['features'];

        // Create a single chunk with all product information
        $chunk = $title . ' ' . $metadata;

        // Add specifications if not empty
        if (!empty($specifications)) {
            $chunk .= ' Specifications: ' . $specifications;
        }

        // Add features if not empty
        if (!empty($features)) {
            $chunk .= ' Features: ' . $features;
        }

        return $chunk;
    }

}
