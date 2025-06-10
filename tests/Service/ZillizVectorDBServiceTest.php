<?php

namespace App\Tests\Service;

use App\Service\ZillizVectorDBService;
use HelgeSverre\Milvus\Milvus as MilvusClient;
use PHPUnit\Framework\TestCase;

class ZillizVectorDBServiceTest extends TestCase
{
    private MilvusClient $mockMilvusClient;

    protected function setUp(): void
    {
        $this->mockMilvusClient = $this->createMock(MilvusClient::class);
    }

    public function testConstructorSuccess(): void
    {
        $service = new ZillizVectorDBService($this->mockMilvusClient, 'test_collection', 1536);
        $this->assertInstanceOf(ZillizVectorDBService::class, $service);
    }

    public function testConstructorThrowsExceptionForEmptyCollectionName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Milvus collection name environment variable (MILVUS_COLLECTION) cannot be empty.');
        new ZillizVectorDBService($this->mockMilvusClient, '', 1536);
    }
     public function testConstructorThrowsExceptionForWhitespaceCollectionName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Milvus collection name environment variable (MILVUS_COLLECTION) cannot be empty.');
        new ZillizVectorDBService($this->mockMilvusClient, '   ', 1536);
    }
}
