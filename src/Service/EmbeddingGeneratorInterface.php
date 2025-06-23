<?php

namespace App\Service;

use App\Entity\Product;

interface EmbeddingGeneratorInterface
{
    /**
     * Generate embeddings for the different parts of a product.
     *
     * Each returned item must contain the embedding vector and the type of the
     * processed chunk (e.g. "specification", "feature", "description", or
     * "generic").
     *
     * @param Product $product The product entity to generate embeddings for.
     * @return array<int, array{vector: array<int, float>, type: string}> List of
     *         embeddings with their type.
     */
    public function generateProductEmbeddings(Product $product): array;

    /**
     * Generates an embedding vector for the given search query.
     *
     * @param string $query The search query to embed.
     * @return array<int, float> Embedding vector representing the query.
     */
    public function generateQueryEmbedding(string $query): array;
}
