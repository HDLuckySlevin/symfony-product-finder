<?php

namespace App\Service;

// Product entity is no longer directly used for generating embeddings here
// use App\Entity\Product;

interface EmbeddingGeneratorInterface
{
    /**
     * Generates an embedding vector for the given text.
     *
     * @param string $text The text to generate an embedding for.
     * @return array<int, float> Embedding vector representing the text.
     */
    public function generateTextEmbedding(string $text): array;

    /**
     * Generates an embedding vector for the given image URL.
     *
     * @param string $imageUrl The URL of the image to generate an embedding for.
     * @return array<int, float> Embedding vector representing the image.
     */
    public function generateImageEmbedding(string $imageUrl): array;

    /**
     * Generates an embedding vector for the given search query.
     *
     * @param string $query The search query to embed.
     * @return array<int, float> Embedding vector representing the query.
     */
    public function generateQueryEmbedding(string $query): array;
}
