<?php

namespace App\Tests\Service;

use App\Service\OpenAIVisionService;
use App\Service\OpenAIClientInterface; // Use the interface for mocking
use App\Service\ChatResourceInterface; // Use the interface for mocking
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Exception\ErrorException; // Re-ensure this is present and used
use OpenAI\Responses\Meta\MetaInformation; // Corrected use statement if this is the FQCN
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for OpenAIVisionService.
 *
 * @covers \App\Service\OpenAIVisionService
 */
class OpenAIVisionServiceTest extends TestCase
{
    private MockObject $openAiClientMock; // This will now be a mock of OpenAIClientInterface
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
        $this->dummyImagePath = $this->tempDir . '/dummy.png'; // Changed to .png
        // Create a tiny valid PNG for testing
        $tinyPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
        file_put_contents($this->dummyImagePath, base64_decode($tinyPngBase64));

        $this->openAiClientMock = $this->createMock(OpenAIClientInterface::class); // Mock our interface
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
        $chatMock = $this->createMock(ChatResourceInterface::class); // Mock our chat interface
        $this->openAiClientMock->method('chat')->willReturn($chatMock);

        // The response structure from OpenAI\Responses\Chat\CreateResponse is complex.
        // We need to ensure our mock CreateResponse::from matches what the actual client would provide,
        // or simplify if only specific fields are used (like choices[0]->message->content).
        // For this test, the important part is choices[0]->message->content.
        // $mockChoice = new \stdClass(); // Not needed if using CreateResponse::from
        // $mockMessage = new \stdClass(); // Not needed
        // $mockMessage->content = 'Test description';
        // $mockChoice->message = $mockMessage;

        // Create a valid mock for OpenAI\Responses\Chat\CreateResponse
        // This static factory method is part of the actual openai-php client library.
        // It expects an array that matches the API response structure.
        $mockApiResponseData = [
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Test description',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
        $mockMeta = MetaInformation::from([]); // Attempting to use the correct FQCN
        $mockApiResponse = CreateResponse::from($mockApiResponseData, $mockMeta);


        $chatMock->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($params) {
                $this->assertEquals('gpt-4o', $params['model']);
                $this->assertCount(1, $params['messages']);
                $messageContent = $params['messages'][0]['content'];
                $this->assertCount(2, $messageContent);
                $this->assertEquals('Test prompt', $messageContent[0]['text']);
                $this->assertStringStartsWith('data:image/png;base64,', $messageContent[1]['image_url']['url']); // Corrected to png
                // Verify base64 content if necessary
                $tinyPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
                $expectedBase64 = $tinyPngBase64; // The dummy image is now the 1x1 png
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

        $this->expectException(\InvalidArgumentException::class); // Corrected: Expect InvalidArgumentException
        $this->expectExceptionMessage("Image file not found or not readable at path: {$unreadableImagePath}"); // Corrected: Message for InvalidArgumentException

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
        $chatMock = $this->createMock(ChatResourceInterface::class); // Mock our chat interface
        $this->openAiClientMock->method('chat')->willReturn($chatMock);

        $chatMock->method('create')
                 ->willThrowException(new \OpenAI\Exception\ErrorException(['message' => 'API Error', 'type' => 'api_error'])); // Use FQCN

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API Error: API Error');
        $this->openAIVisionService->getDescriptionForImage($this->dummyImagePath, 'Test prompt');
    }

    /**
     * Test that an InvalidArgumentException is thrown for unsupported MIME types.
     */
    public function testUnsupportedMimeTypeThrowsException(): void
    {
        $unsupportedFilePath = $this->tempDir . '/dummy_unsupported.txt';
        file_put_contents($unsupportedFilePath, 'This is a text file, not an image.');

        // Determine what mime_content_type will likely return for a .txt file
        // On most systems, this will be 'text/plain'.
        $expectedMimeType = mime_content_type($unsupportedFilePath);
        if ($expectedMimeType === 'image/jpeg' || $expectedMimeType === 'image/png' || $expectedMimeType === 'image/gif' || $expectedMimeType === 'image/webp') {
            $this->markTestSkipped("The environment identified the .txt file as an allowed image type ({$expectedMimeType}), skipping test for unsupported MIME type.");
        }

        $this->expectException(\InvalidArgumentException::class);
        // Hardcode the list for the assertion message as the constant is private in the service
        $allowedTypesString = implode(', ', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        $this->expectExceptionMessage("Unsupported image MIME type: {$expectedMimeType}. Allowed types are: " . $allowedTypesString);

        try {
            $this->openAIVisionService->getDescriptionForImage($unsupportedFilePath, 'Test prompt');
        } finally {
            if (file_exists($unsupportedFilePath)) {
                unlink($unsupportedFilePath);
            }
        }
    }
}
