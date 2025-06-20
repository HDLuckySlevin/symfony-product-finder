<?php

namespace App\Service;

use App\Entity\Product;

interface EmbeddingGeneratorInterface
{
    /**
     * Generates an embedding vector for the given product.
     *
     * @param Product $product The product entity to generate an embedding for.
     * @return array<int, float> Embedding vector representing the product.
     */
    public function generateEmbedding(Product $product): array;

    /**
     * Generates an embedding vector for the given search query.
     *
     * @param string $query The search query to embed.
     * @return array<int, float> Embedding vector representing the query.
     */
    public function generateQueryEmbedding(string $query): array;

    /**
     * Generates an embedding vector for the given image URL.
     *
     * @param string $imageUrl The URL of the image to generate an embedding for.
     * @return array<int, float> Embedding vector representing the image.
     */
    public function generateImageEmbedding(string $imageUrl): array;
}
