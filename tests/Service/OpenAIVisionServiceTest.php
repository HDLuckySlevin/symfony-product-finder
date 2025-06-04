<?php

namespace App\Tests\Service;

use App\Service\OpenAIVisionService;
use OpenAI\Client;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Exception\ErrorException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for OpenAIVisionService.
 *
 * @covers \App\Service\OpenAIVisionService
 */
class OpenAIVisionServiceTest extends TestCase
{
    private MockObject $openAiClientMock;
    private OpenAIVisionService $openAIVisionService;
    private string $tempDir;
    private string $dummyImagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/openai_vision_tests';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
        $this->dummyImagePath = $this->tempDir . '/dummy.jpg';
        file_put_contents($this->dummyImagePath, 'dummy image content');

        $this->openAiClientMock = $this->createMock(Client::class);
        $this->openAIVisionService = new OpenAIVisionService($this->openAiClientMock);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dummyImagePath)) {
            unlink($this->dummyImagePath);
        }
        if (is_dir($this->tempDir)) {
            // Remove any other files in tempDir before removing dir itself
            $files = glob($this->tempDir . '/*');
            foreach($files as $file){
                if(is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * Test successful image description retrieval.
     */
    public function testSuccessfulImageDescription(): void
    {
        $chatMock = $this->createMock(Chat::class);
        $this->openAiClientMock->method('chat')->willReturn($chatMock);

        $mockChoice = new \stdClass();
        $mockMessage = new \stdClass();
        $mockMessage->content = 'Test description';
        $mockChoice->message = $mockMessage;
        // Correctly mock CreateResponse object
        $mockApiResponse = CreateResponse::from(
            ['id' => 'chatcmpl-123', 'object' => 'chat.completion', 'created' => time(), 'model' => 'gpt-4o', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Test description'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]],
            null // No meta needed for this test
        );


        $chatMock->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($params) {
                $this->assertEquals('gpt-4o', $params['model']);
                $this->assertCount(1, $params['messages']);
                $messageContent = $params['messages'][0]['content'];
                $this->assertCount(2, $messageContent);
                $this->assertEquals('Test prompt', $messageContent[0]['text']);
                $this->assertStringStartsWith('data:image/jpeg;base64,', $messageContent[1]['image_url']['url']);
                // Verify base64 content if necessary
                $expectedBase64 = base64_encode('dummy image content');
                $this->assertStringContainsString($expectedBase64, $messageContent[1]['image_url']['url']);
                return true;
            }))
            ->willReturn($mockApiResponse);

        $description = $this->openAIVisionService->getDescriptionForImage($this->dummyImagePath, 'Test prompt');
        $this->assertEquals('Test description', $description);
    }

    /**
     * Test that an InvalidArgumentException is thrown if the image file is not found.
     */
    public function testImageNotFoundThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Image file not found or not readable at path: .*non_existent_image.jpg/");
        $this->openAIVisionService->getDescriptionForImage('non_existent_image.jpg', 'Test prompt');
    }

    /**
     * Test that a RuntimeException is thrown if reading the image fails.
     * This test relies on the file being unreadable.
     */
    public function testImageReadErrorThrowsRuntimeException(): void
    {
        $unreadableImagePath = $this->tempDir . '/unreadable.jpg';
        file_put_contents($unreadableImagePath, 'content');
        // Try to make it unreadable. This might not work on all systems or if PHP runs as root.
        // If chmod fails to make it unreadable, this test might not be reliable.
        $chmodResult = @chmod($unreadableImagePath, 0000);

        if ($chmodResult === false || is_readable($unreadableImagePath)) {
             $this->markTestSkipped('Could not make file unreadable to reliably test file_get_contents failure. Check permissions or test environment.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to read image content from path: {$unreadableImagePath}");

        try {
            $this->openAIVisionService->getDescriptionForImage($unreadableImagePath, 'Test prompt');
        } finally {
            // Restore permissions to allow cleanup
            @chmod($unreadableImagePath, 0666);
            if (file_exists($unreadableImagePath)) {
                unlink($unreadableImagePath);
            }
        }
    }


    /**
     * Test that a RuntimeException is thrown if the OpenAI API returns an error.
     */
    public function testApiErrorThrowsRuntimeException(): void
    {
        $chatMock = $this->createMock(Chat::class);
        $this->openAiClientMock->method('chat')->willReturn($chatMock);

        $chatMock->method('create')
                 ->willThrowException(new ErrorException(['message' => 'API Error', 'type' => 'api_error']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API Error: API Error');
        $this->openAIVisionService->getDescriptionForImage($this->dummyImagePath, 'Test prompt');
    }
}
