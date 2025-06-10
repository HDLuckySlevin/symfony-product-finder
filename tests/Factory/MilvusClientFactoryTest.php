<?php

namespace App\Tests\Factory;

use App\Factory\MilvusClientFactory;
use HelgeSverre\Milvus\Milvus as MilvusClient;
use PHPUnit\Framework\TestCase;

class MilvusClientFactoryTest extends TestCase
{
    public function testCreateSuccess(): void
    {
        $client = MilvusClientFactory::create('localhost', 19530, 'test-token');
        $this->assertInstanceOf(MilvusClient::class, $client);
    }

    public function invalidConfigProvider(): array
    {
        return [
            'empty host' => ['', 19530, 'token', 'Milvus host environment variable (MILVUS_HOST) cannot be empty.'],
            'null host' => [null, 19530, 'token', 'Milvus host environment variable (MILVUS_HOST) cannot be empty.'],
            'whitespace host' => ['   ', 19530, 'token', 'Milvus host environment variable (MILVUS_HOST) cannot be empty.'],
            'zero port' => ['host', 0, 'token', 'Milvus port environment variable (MILVUS_PORT) must be a positive integer.'],
            'negative port' => ['host', -1, 'token', 'Milvus port environment variable (MILVUS_PORT) must be a positive integer.'],
            'non-numeric port' => ['host', 'abc', 'token', 'Milvus port environment variable (MILVUS_PORT) must be a positive integer.'],
            'empty token' => ['host', 19530, '', 'Milvus token/API key environment variable (MILVUS_API_KEY) cannot be empty.'],
            'null token' => ['host', 19530, null, 'Milvus token/API key environment variable (MILVUS_API_KEY) cannot be empty.'],
            'whitespace token' => ['host', 19530, '  ', 'Milvus token/API key environment variable (MILVUS_API_KEY) cannot be empty.'],
        ];
    }

    /**
     * @dataProvider invalidConfigProvider
     */
    public function testCreateThrowsExceptionForInvalidConfig($host, $port, $token, $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        MilvusClientFactory::create($host, $port, $token);
    }
}
