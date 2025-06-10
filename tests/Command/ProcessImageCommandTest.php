<?php

namespace App\Tests\Command;

use App\Command\ProcessImageCommand;
use App\Service\OpenAIVisionService;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Command\Command; // For Command::SUCCESS etc.

/**
 * Tests for ProcessImageCommand.
 *
 * @covers \App\Command\ProcessImageCommand
 */
use App\Service\EmbeddingGeneratorInterface;
use App\Service\ZillizVectorDBService;

/**
 * Tests for ProcessImageCommand.
 *
 * @covers \App\Command\ProcessImageCommand
 */
class ProcessImageCommandTest extends KernelTestCase
{
    private MockObject $mockVisionService;
    private MockObject $mockEmbeddingGenerator;
    private MockObject $mockVectorDBService;
    private CommandTester $commandTester;
    private string $tempDir;
    private string $dummyImagePath;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel(); // Ensure kernel is booted first

        $this->tempDir = sys_get_temp_dir() . '/process_image_command_tests';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
        $this->dummyImagePath = $this->tempDir . '/dummy-command-test.png'; // Changed to .png for clarity
        // Create a tiny valid PNG for testing getimagesize()
        $tinyPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
        file_put_contents($this->dummyImagePath, base64_decode($tinyPngBase64));

        // Create the mock object for OpenAIVisionService
        $this->mockVisionService = $this->createMock(OpenAIVisionService::class);
        $this->mockEmbeddingGenerator = $this->createMock(EmbeddingGeneratorInterface::class);
        $this->mockVectorDBService = $this->createMock(ZillizVectorDBService::class);

        // Get the special test service container
        $container = self::getContainer();

        // Replace the actual services with mock instances in the test container
        $container->set(OpenAIVisionService::class, $this->mockVisionService);
        $container->set(EmbeddingGeneratorInterface::class, $this->mockEmbeddingGenerator);
        $container->set(ZillizVectorDBService::class, $this->mockVectorDBService);

        // Initialize Application and CommandTester
        $application = new Application(self::$kernel); // Use self::$kernel after bootKernel()
        $command = $application->find('app:process-image');
        $this->commandTester = new CommandTester($command);
        // $this->commandTester->setDecorated(false); // Removed this line
    }

    /**
     * Test successful execution of the command.
     */
    public function testExecuteSuccessfully(): void
    {
        $sampleDescription = 'A red bicycle parked near a bench.';
        $dummyEmbedding = [0.1, 0.2, 0.3];

        // Updated mock result to be an array of associative arrays
        $similarProductsResult = [
            ['primary_key' => 'P123', 'title' => 'Awesome Red Bike', 'distance' => 0.95],
            ['primary_key' => 'P456', 'title' => 'Similar Red Pedal Bike', 'distance' => 0.92345],
            // Note: The command formats 'distance' or 'score'. Ensure 'distance' is used here for consistency
            // or that the command correctly falls back to 'score' if that's intended for some mock cases.
            // The command currently prioritizes 'distance'.
        ];

        $this->mockVisionService->expects($this->once())
            ->method('getDescriptionForImage')
            ->with($this->dummyImagePath, 'Test prompt for success')
            ->willReturn($sampleDescription);

        $this->mockEmbeddingGenerator->expects($this->once())
            ->method('generateQueryEmbedding')
            ->with($sampleDescription)
            ->willReturn($dummyEmbedding);

        $this->mockVectorDBService->expects($this->once())
            ->method('searchSimilarProducts')
            ->with($dummyEmbedding, 5)
            ->willReturn($similarProductsResult);

        $this->commandTester->execute(
            [
                'image_path' => $this->dummyImagePath,
                'preprompt' => 'Test prompt for success',
            ],
            ['decorated' => false] // Pass decorated option here
        );

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->commandTester->getDisplay();

        // Check vision service output
        $this->assertStringContainsString($sampleDescription, $output);
        $this->assertStringContainsString('[OK] Successfully retrieved description:', $output);

        // Check embedding generator output
        $this->assertStringContainsString('Embedding generated successfully.', $output);

        // Check vector DB search output
        $this->assertStringContainsString('Top 5 Similar Products:', $output);
        $this->assertStringContainsString('ID', $output); // Table header
        $this->assertStringContainsString('Product Name', $output); // Table header
        $this->assertStringContainsString('Similarity Score', $output); // Table header

        $this->assertStringContainsString('P123', $output);
        $this->assertStringContainsString('Awesome Red Bike', $output);
        $this->assertStringContainsString('0.9500', $output); // Formatted score

        $this->assertStringContainsString('P456', $output);
        $this->assertStringContainsString('Similar Red Pedal Bike', $output);
        $this->assertStringContainsString('0.9235', $output); // Formatted score
    }

    /**
     * Test command execution when embedding generation fails.
     */
    public function testExecuteEmbeddingFailure(): void
    {
        $sampleDescription = 'A red bicycle.';
        $this->mockVisionService->expects($this->once())
            ->method('getDescriptionForImage')
            ->with($this->dummyImagePath, 'Test prompt for embedding failure')
            ->willReturn($sampleDescription);

        $this->mockEmbeddingGenerator->expects($this->once())
            ->method('generateQueryEmbedding')
            ->with($sampleDescription)
            ->will($this->throwException(new \RuntimeException('Embedding API error')));

        $this->commandTester->execute(
            [
                'image_path' => $this->dummyImagePath,
                'preprompt' => 'Test prompt for embedding failure',
            ],
            ['decorated' => false]
        );

        $this->assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[ERROR] Failed to generate embedding for the description: Embedding API error', $output);
    }

    /**
     * Test command execution when vector database search fails.
     */
    public function testExecuteVectorSearchFailure(): void
    {
        $sampleDescription = 'A blue car.';
        $dummyEmbedding = [0.4, 0.5, 0.6];

        $this->mockVisionService->expects($this->once())
            ->method('getDescriptionForImage')
            ->with($this->dummyImagePath, 'Test prompt for vector search failure')
            ->willReturn($sampleDescription);

        $this->mockEmbeddingGenerator->expects($this->once())
            ->method('generateQueryEmbedding')
            ->with($sampleDescription)
            ->willReturn($dummyEmbedding);

        $this->mockVectorDBService->expects($this->once())
            ->method('searchSimilarProducts')
            ->with($dummyEmbedding, 5)
            ->will($this->throwException(new \RuntimeException('Vector DB error')));

        $this->commandTester->execute(
            [
                'image_path' => $this->dummyImagePath,
                'preprompt' => 'Test prompt for vector search failure',
            ],
            ['decorated' => false]
        );

        $this->assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[ERROR] Failed to search for similar products: Vector DB error', $output);
    }

    /**
     * Test command execution when no similar products are found.
     */
    public function testExecuteNoSimilarProductsFound(): void
    {
        $sampleDescription = 'A unique widget.';
        $dummyEmbedding = [0.7, 0.8, 0.9];

        $this->mockVisionService->expects($this->once())
            ->method('getDescriptionForImage')
            ->with($this->dummyImagePath, 'Test prompt for no products')
            ->willReturn($sampleDescription);

        $this->mockEmbeddingGenerator->expects($this->once())
            ->method('generateQueryEmbedding')
            ->with($sampleDescription)
            ->willReturn($dummyEmbedding);

        $this->mockVectorDBService->expects($this->once())
            ->method('searchSimilarProducts')
            ->with($dummyEmbedding, 5)
            ->willReturn([]); // Return an empty array

        $this->commandTester->execute(
            [
                'image_path' => $this->dummyImagePath,
                'preprompt' => 'Test prompt for no products',
            ],
            ['decorated' => false]
        );

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString($sampleDescription, $output);
        $this->assertStringContainsString('Embedding generated successfully.', $output);
        $this->assertStringContainsString('Top 5 Similar Products:', $output);
        $this->assertStringContainsString('No similar products found.', $output);
    }

    /**
     * Test command execution when the vision service throws a RuntimeException.
     */
    public function testExecuteWithServiceRuntimeException(): void
    {
        $this->mockVisionService->expects($this->once())
            ->method('getDescriptionForImage')
            ->with($this->dummyImagePath, 'Test prompt for service error')
            ->will($this->throwException(new \RuntimeException('Service Error')));

        $this->commandTester->execute(
            [
                'image_path' => $this->dummyImagePath,
                'preprompt' => 'Test prompt for service error',
            ],
            ['decorated' => false]
        );

        $this->assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // SymfonyStyle error blocks start with [ERROR]
        $this->assertStringContainsString('[ERROR] Runtime error during processing: Service Error', $output);
    }

    /**
     * Test command execution with an invalid image path (service throws InvalidArgumentException).
     * This test assumes the file exists and is a valid image format, but the service has a reason to reject it
     * (e.g. content-related, or a specific path the service is mocked to consider invalid argument for).
     * The command's own file_exists and getimagesize checks would pass.
     */
    public function testExecuteWithInvalidImagePath(): void
    {
        // Using the valid dummy image path, but mocking the service to throw InvalidArgumentException for it.
        $this->mockVisionService->expects($this->once()) // Use the renamed mock property
            ->method('getDescriptionForImage')
            ->with($this->dummyImagePath, 'Test prompt for invalid path by service')
            ->will($this->throwException(new \InvalidArgumentException('Service rejected image argument')));

        $this->commandTester->execute(
            [
                'image_path' => $this->dummyImagePath, // Valid image, but service will say invalid argument
                'preprompt' => 'Test prompt for invalid path by service',
            ],
            ['decorated' => false]
        );

        $this->assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // This message comes from the command's catch block for InvalidArgumentException
        $this->assertStringContainsString('[ERROR] Invalid argument or setup: Service rejected image argument', $output);
    }

    /**
     * Test command execution when a required argument (image_path) is missing.
     */
    public function testExecuteWithMissingImagePathArgument(): void
    {
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        // The message might vary slightly based on Symfony version, but it will indicate a missing argument.
        $this->expectExceptionMessageMatches('/Not enough arguments \(missing: "image_path"\)\.|Argument "image_path" is not defined\./');

        // CommandTester throws an exception if required arguments are not provided.
        // The status code Command::INVALID is typically for validation errors within execute,
        // not for missing arguments handled by Symfony's input definition.
        $this->commandTester->execute([
            // 'image_path' => 'some/path.jpg', // Missing
            'preprompt' => 'Test prompt with missing path',
        ]);
        // Note: If the command execution doesn't throw an exception for missing args (e.g. if input def is changed),
        // then we would check status code and output.
        // $this->assertEquals(Command::INVALID, $this->commandTester->getStatusCode());
        // $output = $this->commandTester->getDisplay();
        // $this->assertStringContainsString('image_path argument is missing', $output);
    }

    /**
     * Test command execution with a non-existent image path argument.
     */
    public function testExecuteWithNonExistentImagePathArgument(): void
    {
        $nonExistentPath = $this->tempDir . '/this_file_does_not_exist.jpg';

        $this->mockVisionService->expects($this->never()) // Use the renamed mock property
            ->method('getDescriptionForImage');

        $this->commandTester->execute(
            [
                'image_path' => $nonExistentPath,
                'preprompt' => 'Test prompt for non-existent path',
            ],
            ['decorated' => false]
        );

        $this->assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // This message comes directly from the command's initial file_exists check
        $this->assertStringContainsString("[ERROR] Error: Image file not found at path: ", $output);
        $this->assertStringContainsString($nonExistentPath, $output);
    }

    /**
     * Test command execution with a non-image file argument.
     */
    public function testExecuteWithNonImageFileArgument(): void
    {
        $nonImagePath = $this->tempDir . '/dummy.txt';
        file_put_contents($nonImagePath, 'This is not an image.');

        $this->mockVisionService->expects($this->never()) // Use the renamed mock property
            ->method('getDescriptionForImage');

        $this->commandTester->execute(
            [
                'image_path' => $nonImagePath,
                'preprompt' => 'Test prompt for non-image file',
            ],
            ['decorated' => false]
        );

        $this->assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // This message comes directly from the command's initial getimagesize check
        $this->assertStringContainsString("[ERROR] Error: The file at path is not a valid image or is corrupted: ", $output);
        $this->assertStringContainsString($nonImagePath, $output);
    }


    protected function tearDown(): void
    {
        // Clean up main dummy image
        if (file_exists($this->dummyImagePath)) {
            unlink($this->dummyImagePath);
        }
        // Clean up potential non-image file
        $nonImagePath = $this->tempDir . '/dummy.txt';
        if (file_exists($nonImagePath)) {
            unlink($nonImagePath);
        }

        if (is_dir($this->tempDir)) {
             $files = glob($this->tempDir . '/*'); // Get all file names
            foreach($files as $file){ // Iterate files
                if(is_file($file)) {
                    unlink($file); // Delete file
                }
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }
}
