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
class ProcessImageCommandTest extends KernelTestCase
{
    private MockObject $visionServiceMock;
    private CommandTester $commandTester;
    private string $tempDir;
    private string $dummyImagePath;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->tempDir = sys_get_temp_dir() . '/process_image_command_tests';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
        $this->dummyImagePath = $this->tempDir . '/dummy-command-test.jpg';
        file_put_contents($this->dummyImagePath, 'dummy command image content');

        $this->visionServiceMock = $this->createMock(OpenAIVisionService::class);

        $application = new Application(static::$kernel);

        // Instead of adding a new command, we ensure the service container uses our mock
        // for the OpenAIVisionService when ProcessImageCommand is instantiated by Symfony.
        // This requires that ProcessImageCommand is registered as a service and autowired.
        // We can override the service in the test container.
        if (static::getContainer()->has(OpenAIVisionService::class)) {
            static::getContainer()->set(OpenAIVisionService::class, $this->visionServiceMock);
        } else {
            // Fallback or error if service definition is not as expected
            // For now, we assume it's correctly set up for autowiring or explicitly defined
            // If not, direct instantiation was the previous approach:
            // $application->add(new ProcessImageCommand($this->visionServiceMock));
            // $command = $application->find('app:process-image');
            // $this->commandTester = new CommandTester($command);
            // return;
            // For this test, let's ensure the command is fetched from the application
            // to test the actual command registration and service injection.
        }

        // Fetch the command from the application; it should now use the mocked service
        $command = $application->find('app:process-image');
        $this->commandTester = new CommandTester($command);
    }

    /**
     * Test successful execution of the command.
     */
    public function testExecuteSuccessfully(): void
    {
        $this->visionServiceMock->expects($this->once())
            ->method('getDescriptionForImage')
            ->with($this->dummyImagePath, 'Test prompt for success')
            ->willReturn('Successful description from command test');

        $this->commandTester->execute([
            'image_path' => $this->dummyImagePath,
            'preprompt' => 'Test prompt for success',
        ]);

        $this->commandTester->assertCommandIsSuccessful();
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Successful description from command test', $output);
        $this->assertStringContainsString('[OK] Successfully retrieved description:', $output); // SymfonyStyle success
    }

    /**
     * Test command execution when the vision service throws a RuntimeException.
     */
    public function testExecuteWithServiceRuntimeException(): void
    {
        $this->visionServiceMock->expects($this->once())
            ->method('getDescriptionForImage')
            ->with($this->dummyImagePath, 'Test prompt for service error')
            ->will($this->throwException(new \RuntimeException('Service Error')));

        $this->commandTester->execute([
            'image_path' => $this->dummyImagePath,
            'preprompt' => 'Test prompt for service error',
        ]);

        $this->assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // SymfonyStyle error blocks start with [ERROR]
        $this->assertStringContainsString('[ERROR] Runtime error during processing: Service Error', $output);
    }

    /**
     * Test command execution with an invalid image path (service throws InvalidArgumentException).
     */
    public function testExecuteWithInvalidImagePath(): void
    {
        $nonExistentPath = $this->tempDir . '/non_existent_image.jpg';
        $this->visionServiceMock->expects($this->once())
            ->method('getDescriptionForImage')
            ->with($nonExistentPath, 'Test prompt for invalid path')
            ->will($this->throwException(new \InvalidArgumentException('Image not found')));

        $this->commandTester->execute([
            'image_path' => $nonExistentPath,
            'preprompt' => 'Test prompt for invalid path',
        ]);

        $this->assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[ERROR] Invalid argument: Image not found', $output);
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


    protected function tearDown(): void
    {
        if (file_exists($this->dummyImagePath)) {
            unlink($this->dummyImagePath);
        }
        if (is_dir($this->tempDir)) {
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
}
