<?php

namespace App\Tests\Service;

use App\Service\PythonEmbeddingGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PythonEmbeddingGeneratorTest extends TestCase
{
    private LoggerInterface $logger;
    private string $embedHost = 'http://localhost';
    private string $embedPort = '5000';

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createGenerator(HttpClientInterface $httpClient): PythonEmbeddingGenerator
    {
        // Mock health check response
        $healthCheckResponse = $this->createMock(ResponseInterface::class);
        $healthCheckResponse->method('getStatusCode')->willReturn(200);
        $healthCheckResponse->method('toArray')->willReturn(['status' => 'It works']);

        // Ensure health check is called and returns the mocked response
        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', $this->embedHost . ':' . $this->embedPort . '/healthstatus')
            ->willReturn($healthCheckResponse);

        return new PythonEmbeddingGenerator($httpClient, $this->logger, $this->embedHost, $this->embedPort);
    }

    public function testGenerateTextEmbeddingSuccess()
    {
        $mockResponse = new MockResponse(json_encode(['vectors' => [[0.1, 0.2, 0.3]]]), ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]); // This client will be used for health check first.

        // We need a fresh client for the actual embedding call, as MockHttpClient consumes responses.
        // Or, provide multiple responses to MockHttpClient.
        $healthCheckMockResponse = new MockResponse(json_encode(['status' => 'It works']), ['http_code' => 200]);
        $embeddingMockResponse = new MockResponse(json_encode(['vectors' => [[0.1, 0.2, 0.3]]]), ['http_code' => 200]);
        $httpClientForTest = new MockHttpClient([$healthCheckMockResponse, $embeddingMockResponse]);

        $generator = new PythonEmbeddingGenerator($httpClientForTest, $this->logger, $this->embedHost, $this->embedPort);

        $text = "This is a test.";
        $embedding = $generator->generateTextEmbedding($text);

        $this->assertEquals([0.1, 0.2, 0.3], $embedding);
        $this->assertCount(2, $httpClientForTest->getRequests()); // Healthcheck + embedding
    }

    public function testGenerateTextEmbeddingEmptyText()
    {
        $httpClient = $this->createMock(HttpClientInterface::class); // Health check will be mocked here
        $generator = $this->createGenerator($httpClient); // Health check happens in constructor

        $embedding = $generator->generateTextEmbedding("   ");
        $this->assertEmpty($embedding);
    }

    public function testGenerateTextEmbeddingApiError()
    {
        $healthCheckMockResponse = new MockResponse(json_encode(['status' => 'It works']), ['http_code' => 200]);
        $errorMockResponse = new MockResponse(json_encode(['error' => 'API down']), ['http_code' => 500]);
        $httpClientForTest = new MockHttpClient([$healthCheckMockResponse, $errorMockResponse]);

        $generator = new PythonEmbeddingGenerator($httpClientForTest, $this->logger, $this->embedHost, $this->embedPort);

        $embedding = $generator->generateTextEmbedding("test");
        $this->assertEmpty($embedding); // Should return empty on error as per current implementation
    }

    public function testGenerateImageEmbeddingSuccess()
    {
        $healthCheckMockResponse = new MockResponse(json_encode(['status' => 'It works']), ['http_code' => 200]);
        $embeddingMockResponse = new MockResponse(json_encode(['vector' => [0.4, 0.5, 0.6]]), ['http_code' => 200]);
        $httpClientForTest = new MockHttpClient([$healthCheckMockResponse, $embeddingMockResponse]);

        $generator = new PythonEmbeddingGenerator($httpClientForTest, $this->logger, $this->embedHost, $this->embedPort);

        $imageUrl = "http://example.com/image.png";
        $embedding = $generator->generateImageEmbedding($imageUrl);

        $this->assertEquals([0.4, 0.5, 0.6], $embedding);
         $this->assertCount(2, $httpClientForTest->getRequests());
    }

    public function testGenerateImageEmbeddingInvalidUrl()
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $generator = $this->createGenerator($httpClient); // Health check

        $embedding = $generator->generateImageEmbedding("invalid-url");
        $this->assertEmpty($embedding);
    }

    public function testGenerateImageEmbeddingApiError()
    {
        $healthCheckMockResponse = new MockResponse(json_encode(['status' => 'It works']), ['http_code' => 200]);
        $errorMockResponse = new MockResponse(json_encode(['error' => 'Image API down']), ['http_code' => 500]);
        // This response is for the POST to /image-embedding
        $httpClientForTest = new MockHttpClient([$healthCheckMockResponse, $errorMockResponse]);

        $generator = new PythonEmbeddingGenerator($httpClientForTest, $this->logger, $this->embedHost, $this->embedPort);

        $embedding = $generator->generateImageEmbedding("http://example.com/image.jpg");
        // Current implementation of generateImageEmbedding returns [] on HTTP error > 200
        // If it were to throw an exception, this test would need an expectedException
        $this->assertEmpty($embedding);
    }

    public function testGenerateQueryEmbeddingSuccess()
    {
        $healthCheckMockResponse = new MockResponse(json_encode(['status' => 'It works']), ['http_code' => 200]);
        $embeddingMockResponse = new MockResponse(json_encode(['vectors' => [[0.7, 0.8, 0.9]]]), ['http_code' => 200]);
        $httpClientForTest = new MockHttpClient([$healthCheckMockResponse, $embeddingMockResponse]);

        $generator = new PythonEmbeddingGenerator($httpClientForTest, $this->logger, $this->embedHost, $this->embedPort);

        $query = "search query";
        $embedding = $generator->generateQueryEmbedding($query);
        $this->assertEquals([0.7, 0.8, 0.9], $embedding);
    }
}
