<?php

namespace App\Factory;

use HelgeSverre\Milvus\Milvus as MilvusClient;

/**
 * Factory for creating Milvus client instances
 * 
 * This factory provides a static method for creating properly configured
 * Milvus client instances for connecting to the Zilliz/Milvus vector database.
 */
class MilvusClientFactory
{
    /**
     * Create a new Milvus client instance
     * 
     * @param string $host The hostname or IP address of the Milvus server
     * @param int $port The port number of the Milvus server
     * @param string $token The authentication token for the Milvus server
     * @return MilvusClient A configured Milvus client instance
     */
    public static function create(string $host, int $port, /* string */ $token): MilvusClient // Assuming token should be string-like
    {
        if (empty(trim((string) $host))) { // Cast to string for trim
            throw new \InvalidArgumentException('Milvus host environment variable (MILVUS_HOST) cannot be empty.');
        }
        if (empty($port) || !is_numeric($port) || (int)$port <= 0) { // Ensure port is a positive integer
            throw new \InvalidArgumentException('Milvus port environment variable (MILVUS_PORT) must be a positive integer.');
        }
        // Ensure token is treated as string for validation, matching how it's used.
        // The Milvus client itself might have specific expectations for $token type.
        if (empty(trim((string) $token))) { // Cast to string for trim
            throw new \InvalidArgumentException('Milvus token/API key environment variable (MILVUS_API_KEY) cannot be empty.');
        }
        // The Milvus client constructor expects $token to be string.
        return new MilvusClient(host: $host, port: $port, token: (string) $token);
    }
}
