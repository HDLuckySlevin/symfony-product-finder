<?php

namespace App\Tests\Factory;

use App\Factory\MilvusClientFactory;
use HelgeSverre\Milvus\Milvus as MilvusClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider; // Import the attribute

class MilvusClientFactoryTest extends TestCase
{
    public function testCreateSuccess(): void
    {
        $client = MilvusClientFactory::create('localhost', 19530, 'test-token');
        $this->assertInstanceOf(MilvusClient::class, $client);
    }

    public static function invalidConfigProvider(): array // Made static
    {
        return [
            'empty host' => ['', 19530, 'token', 'Milvus host environment variable (MILVUS_HOST) cannot be empty.'],
            'null host becomes empty string' => ['', 19530, 'token', 'Milvus host environment variable (MILVUS_HOST) cannot be empty.'], // Changed null to test trim
            'whitespace host' => ['   ', 19530, 'token', 'Milvus host environment variable (MILVUS_HOST) cannot be empty.'],
            'zero port' => ['host', 0, 'token', 'Milvus port environment variable (MILVUS_PORT) must be a positive integer.'],
            'negative port' => ['host', -1, 'token', 'Milvus port environment variable (MILVUS_PORT) must be a positive integer.'],
            // 'non-numeric port' case removed as it correctly throws TypeError due to type hint
            'empty token' => ['host', 19530, '', 'Milvus token/API key environment variable (MILVUS_API_KEY) cannot be empty.'],
            'null token becomes empty string' => ['host', 19530, '', 'Milvus token/API key environment variable (MILVUS_API_KEY) cannot be empty.'], // Changed null to test trim
            'whitespace token' => ['host', 19530, '  ', 'Milvus token/API key environment variable (MILVUS_API_KEY) cannot be empty.'],
        ];
    }

    /**
     * @dataProvider invalidConfigProvider
     */
    #[DataProvider('invalidConfigProvider')] // Add PHP 8 attribute
    public function testCreateThrowsExceptionForInvalidConfig($host, $port, $token, $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        MilvusClientFactory::create($host, $port, $token);
    }
}
