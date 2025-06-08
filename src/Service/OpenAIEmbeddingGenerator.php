<?php

namespace App\Service;

use App\Entity\Product;
use OpenAI\Client;

/**
 * Service for generating vector embeddings using OpenAI's API
 * 
 * This service implements the EmbeddingGeneratorInterface and provides
 * methods for generating vector embeddings for products and search queries
 * using OpenAI's embedding models. It includes fallback to mock embeddings
 * when the API is unavailable or no API key is provided.
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
        $chunks = $this->getChunks($groupedFields);

        // Generate embedding for the combined text
        return $this->generateEmbeddingForText($chunks[0]);
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
     * representation of the provided text. Falls back to mock embeddings if
     * the API key is not set or if the API call fails.
     * 
     * The text is chunked into smaller pieces with a token size of 500 before
     * being sent to the API. If multiple chunks are generated, only the first
     * chunk is used to produce the embedding vector.
     * 
     * @param string $text The text to generate embeddings for
     * @return array<int, float> The embedding vector
     * @throws \RuntimeException If the API response format is invalid
     */
    private function generateEmbeddingForText(string $text): array
    {
        try {
            // Chunk the text before embedding
            $chunks = $this->chunkText($text, 500);

            // If there's only one chunk, process it directly
            if (count($chunks) === 1) {
                $response = $this->client->embeddings()->create([
                    'model' => $this->embeddingModel,
                    'input' => $chunks[0],
                ]);

                if (isset($response->embeddings[0]->embedding)) {
                    return $response->embeddings[0]->embedding;
                }

                throw new \RuntimeException('Failed to generate embedding: Invalid response format from OpenAI client');
            }

            // For multiple chunks, use only the first chunk
            $response = $this->client->embeddings()->create([
                'model' => $this->embeddingModel,
                'input' => $chunks[0],
            ]);

            if (isset($response->embeddings[0]->embedding)) {
                return $response->embeddings[0]->embedding;
            } else {
                throw new \RuntimeException('Failed to generate embedding: Invalid response format from OpenAI client');
            }
        } catch (\Exception $e) {
            // Log the error message for debugging
            // error_log('OpenAI API Error: ' . $e->getMessage());
            // Fallback to mock embedding on error
            return $this->generateMockEmbedding($text);
        }
    }

    /**
     * Chunk text into smaller pieces based on approximate token size
     * 
     * Splits the input text into chunks of approximately the specified token size.
     * This is a simple implementation that assumes 1 token is roughly 4 characters.
     * 
     * @param string $text The text to chunk
     * @param int $tokenSize The approximate token size for each chunk
     * @return array<int, string> Array of text chunks
     */
    private function chunkText(string $text, int $tokenSize = 500): array
    {
        // Simple approximation: 1 token ≈ 4 characters
        $charsPerToken = 4;
        $chunkSize = $tokenSize * $charsPerToken;

        // If text is smaller than chunk size, return it as is
        if (strlen($text) <= $chunkSize) {
            return [$text];
        }

        $chunks = [];
        $words = explode(' ', $text);
        $currentChunk = '';

        foreach ($words as $word) {
            // If adding this word would exceed the chunk size, start a new chunk
            if (strlen($currentChunk) + strlen($word) + 1 > $chunkSize && !empty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                $currentChunk = '';
            }

            $currentChunk .= ' ' . $word;
        }

        // Add the last chunk if it's not empty
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
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
    private function getChunks(array $groupedFields): array
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

        return [$chunk];
    }

    /**
     * Generate a mock embedding for testing purposes
     * 
     * Creates a deterministic but unique embedding vector based on the MD5 hash
     * of the input text. This is used when no API key is provided or when the
     * API call fails. The generated vector has the same dimensions as OpenAI's
     * embeddings (1536).
     * 
     * @param string $text The text to generate a mock embedding for
     * @return array<int, float> The mock embedding vector with 1536 dimensions
     */
    private function generateMockEmbedding(string $text): array
    {
        // Create a deterministic but unique embedding based on the text
        $hash = md5($text);
        $bytes = str_split($hash, 2);

        // Convert to float values between -1 and 1
        $embedding = [];
        foreach ($bytes as $byte) {
            $value = (hexdec($byte) / 255) * 2 - 1;
            $embedding[] = $value;
        }

        // Efficiently pad to 1536 dimensions without using array_merge in a loop
        $originalLength = count($embedding);

        // Create a padded array of exactly 1536 elements
        $padded = [];
        for ($i = 0; $i < 1536; $i++) {
            // Use modulo to cycle through the original embedding values
            $padded[] = $embedding[$i % $originalLength];
        }

        return $padded;
    }
}
