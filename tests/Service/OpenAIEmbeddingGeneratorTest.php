<?php

namespace App\Tests\Service;

use App\Service\OpenAIEmbeddingGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpClient\Exception\TransportException; // For testing API errors

class OpenAIEmbeddingGeneratorTest extends TestCase
{
    private HttpClientInterface $mockHttpClient;

    protected function setUp(): void
    {
        $this->mockHttpClient = $this->createMock(HttpClientInterface::class);
    }

    public function testConstructorThrowsExceptionIfApiKeyIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OpenAI API key cannot be empty.');
        new OpenAIEmbeddingGenerator($this->mockHttpClient, '', 'text-embedding-3-large');
    }

    public function testConstructorThrowsExceptionIfEmbeddingModelIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OpenAI embedding model cannot be empty.');
        new OpenAIEmbeddingGenerator($this->mockHttpClient, 'dummy-api-key', '');
    }

    public function testGenerateQueryEmbeddingSuccess(): void
    {
        $expectedEmbedding = [0.123, 0.456, 0.789];
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'data' => [
                ['embedding' => $expectedEmbedding]
            ]
        ]);

        $this->mockHttpClient
            ->expects($this->once()) // Ensure API is called
            ->method('request')
            ->with('POST', 'https://api.openai.com/v1/embeddings', $this->callback(function($options) {
                // Check that model and input are in the request payload
                $this->assertArrayHasKey('json', $options);
                $this->assertArrayHasKey('model', $options['json']);
                $this->assertEquals('text-embedding-model', $options['json']['model']); // Check correct model
                $this->assertArrayHasKey('input', $options['json']);
                $this->assertEquals('test query', $options['json']['input']);
                return true;
            }))
            ->willReturn($mockResponse);

        $generator = new OpenAIEmbeddingGenerator($this->mockHttpClient, 'dummy-api-key', 'text-embedding-model');
        $embedding = $generator->generateQueryEmbedding('test query');

        $this->assertEquals($expectedEmbedding, $embedding);
    }

    public function testGenerateQueryEmbeddingHandlesApiErrorAndReturnsMock(): void
    {
        $this->mockHttpClient
            ->method('request')
            ->willThrowException(new TransportException('API communication failed'));

        $generator = new OpenAIEmbeddingGenerator($this->mockHttpClient, 'dummy-api-key', 'text-embedding-model');

        // Test that it falls back to mock embedding
        $embedding = $generator->generateQueryEmbedding('test query on API error');
        $this->assertIsArray($embedding);
        $this->assertCount(1536, $embedding); // Assuming generateMockEmbedding produces 1536 dimensions
        // Further checks could be done if generateMockEmbedding is deterministic and public
    }

    // Optional: Add a test for successful chunking if generateEmbeddingForText is called with long text
    // This would involve mocking multiple HttpClient calls and verifying averageEmbeddings logic.
    // For now, focusing on the primary path and error handling.
}
