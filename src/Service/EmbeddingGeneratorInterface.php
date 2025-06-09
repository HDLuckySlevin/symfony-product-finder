<?php

namespace App\Service;

use App\Entity\Product;

interface EmbeddingGeneratorInterface
{
    /**
     * Generate embeddings for a product
     * 
     * @param Product $product
     * @return array<int, float> The embedding vector
     */
    public function generateEmbedding(Product $product): array;

    /**
     * Generate embeddings for a search query
     * 
     * @param string $query
     * @return array<int, float> The embedding vector
     */
    public function generateQueryEmbedding(string $query): array;
}
