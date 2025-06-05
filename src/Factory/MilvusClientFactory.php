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
    public static function create(string $token, string $host, int $port): MilvusClient
    {
        return new MilvusClient(token: $token, host: $host, port: $port);
    }
}
