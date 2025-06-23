<?php

namespace App\Tests\Service;

use App\Service\MilvusVectorStoreService;
use HelgeSverre\Milvus\Milvus as MilvusClient;
use HelgeSverre\Milvus\Resources\CollectionResource;
use HelgeSverre\Milvus\Resources\VectorResource;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Log\LoggerInterface;

class MilvusVectorStoreServiceTest extends TestCase
{
    private MilvusClient $milvusClientMock;
    private LoggerInterface $loggerMock;
    private CollectionResource $collectionResourceMock;
    private VectorResource $vectorResourceMock;
    private MilvusVectorStoreService $service;

    private string $collectionName = 'test_collection';
    private int $dimension = 128;

    protected function setUp(): void
    {
        $this->milvusClientMock = $this->createMock(MilvusClient::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->collectionResourceMock = $this->createMock(CollectionResource::class);
        $this->vectorResourceMock = $this->createMock(VectorResource::class);

        $this->milvusClientMock->method('collections')->willReturn($this->collectionResourceMock);
        $this->milvusClientMock->method('vector')->willReturn($this->vectorResourceMock);

        $this->service = new MilvusVectorStoreService(
            $this->milvusClientMock,
            $this->loggerMock,
            $this->collectionName,
            $this->dimension
        );
    }

    public function testInitializeCollectionWhenExists()
    {
        $responseMock = $this->createMock(PsrResponseInterface::class);
        $responseMock->method('json')->willReturn(['data' => [$this->collectionName]]);
        $this->collectionResourceMock->method('list')->willReturn($responseMock);

        $this->assertTrue($this->service->initializeCollection());
    }

    public function testInitializeCollectionWhenNotExistsAndCreateSuccess()
    {
        $listResponseMock = $this->createMock(PsrResponseInterface::class);
        $listResponseMock->method('json')->willReturn(['data' => ['another_collection']]);
        $this->collectionResourceMock->method('list')->willReturn($listResponseMock);

        $this->collectionResourceMock->expects($this->once())
            ->method('create')
            ->with(
                collectionName: $this->collectionName,
                dimension: $this->dimension,
                primaryField: "embedding_id",
                vectorField: "vector",
                metricType: "COSINE",
                autoId: true,
                description: "Collection for fine-grained product embeddings"
            );

        $this->assertTrue($this->service->initializeCollection());
    }

    public function testInsertEmbeddingsSuccess()
    {
        $embeddingDataList = [
            ['product_id' => 1, 'product_name' => 'Product A', 'type' => 'description', 'vector' => [0.1, 0.2]],
            ['product_id' => 1, 'product_name' => 'Product A', 'type' => 'feature', 'vector' => [0.3, 0.4]],
            ['product_id' => 2, 'product_name' => 'Product B', 'type' => 'image', 'vector' => [0.5, 0.6]],
        ];

        $expectedDataForMilvus = [
            'product_id' => [1, 1, 2],
            'product_name' => ['Product A', 'Product A', 'Product B'],
            'type' => ['description', 'feature', 'image'],
            'vector' => [[0.1, 0.2], [0.3, 0.4], [0.5, 0.6]],
        ];

        $psrResponseMock = $this->createMock(PsrResponseInterface::class);
        // Assuming the SDK's insert method returns a PSR response which can be checked,
        // or simply doesn't throw for success.
        // If it returns specific data like insert_count, mock that.
        // $psrResponseMock->method('json')->willReturn(['data' => ['insert_count' => 3]]);


        $this->vectorResourceMock->expects($this->once())
            ->method('insert')
            ->with(
                collectionName: $this->collectionName,
                data: $expectedDataForMilvus
            )->willReturn($psrResponseMock); // Ensure insert returns a mock that doesn't break the flow

        $this->assertTrue($this->service->insertEmbeddings($embeddingDataList));
    }

    public function testInsertEmbeddingsEmptyList()
    {
        $this->vectorResourceMock->expects($this->never())->method('insert');
        $this->assertTrue($this->service->insertEmbeddings([]));
    }

    public function testInsertEmbeddingsSkipsItemWithEmptyVector()
    {
        $embeddingDataList = [
            ['product_id' => 1, 'product_name' => 'Product A', 'type' => 'description', 'vector' => [0.1, 0.2]],
            ['product_id' => 1, 'product_name' => 'Product A', 'type' => 'feature', 'vector' => []], // Empty vector
        ];

        $expectedDataForMilvus = [
            'product_id' => [1],
            'product_name' => ['Product A'],
            'type' => ['description'],
            'vector' => [[0.1, 0.2]],
        ];

        $psrResponseMock = $this->createMock(PsrResponseInterface::class);
        $this->vectorResourceMock->expects($this->once())
            ->method('insert')
            ->with(collectionName: $this->collectionName, data: $expectedDataForMilvus)
            ->willReturn($psrResponseMock);

        $this->assertTrue($this->service->insertEmbeddings($embeddingDataList));
    }

    public function testSearchSimilarProductsSuccess()
    {
        $queryEmbedding = [0.1, 0.2, 0.3];
        $limit = 5;
        $expectedOutputFields = ["embedding_id", "product_id", "product_name", "type"];
        $mockSearchResults = ['data' => [['id' => 'emb1', 'product_id' => 10, 'product_name' => 'Searched Product', 'type' => 'description', 'distance' => 0.9]]];

        $responseMock = $this->createMock(PsrResponseInterface::class);
        $responseMock->method('json')->willReturn($mockSearchResults);

        $this->vectorResourceMock->expects($this->once())
            ->method('search')
            ->with(
                collectionName: $this->collectionName,
                vector: $queryEmbedding,
                limit: $limit,
                outputFields: $expectedOutputFields,
                dbName: $this->collectionName
            )->willReturn($responseMock);

        $results = $this->service->searchSimilarProducts($queryEmbedding, $limit);
        $this->assertEquals($mockSearchResults['data'], $results);
    }
}
