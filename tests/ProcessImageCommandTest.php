<?php

namespace App\Tests;

use App\Command\ProcessImageCommand;
use App\Service\PythonEmbeddingGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class ProcessImageCommandTest extends TestCase
{
    public function testExecuteRunsSearchCommandWithDescription(): void
    {
        $generator = $this->createMock(PythonEmbeddingGenerator::class);
        $generator->expects($this->once())
            ->method('describeImage')
            ->willReturn('awesome phone');

        $searchCommand = new class extends Command {
            public ?string $receivedQuery = null;
            protected static $defaultName = 'app:test-search';
            protected function configure(): void
            {
                $this->addArgument('query', InputArgument::REQUIRED);
            }
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->receivedQuery = $input->getArgument('query');
                return Command::SUCCESS;
            }
        };

        $command = new ProcessImageCommand($generator);
        $application = new Application();
        $application->add($command);
        $application->add($searchCommand);
        $command->setApplication($application);

        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, 'fake');

        $tester = new CommandTester($command);
        $tester->execute(['image' => $tmp]);

        $this->assertEquals('awesome phone', $searchCommand->receivedQuery);
        $this->assertEquals(Command::SUCCESS, $tester->getStatusCode());

        unlink($tmp);
    }

    public function testExecuteFailsOnMissingDescription(): void
    {
        $generator = $this->createMock(PythonEmbeddingGenerator::class);
        $generator->method('describeImage')->willReturn(null);

        $searchCommand = new class extends Command {
            protected static $defaultName = 'app:test-search';
            protected function configure(): void
            {
                $this->addArgument('query');
            }
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return Command::SUCCESS;
            }
        };

        $command = new ProcessImageCommand($generator);
        $application = new Application();
        $application->add($command);
        $application->add($searchCommand);
        $command->setApplication($application);

        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, 'fake');

        $tester = new CommandTester($command);
        $tester->execute(['image' => $tmp]);

        $this->assertEquals(Command::FAILURE, $tester->getStatusCode());

        unlink($tmp);
    }
}

