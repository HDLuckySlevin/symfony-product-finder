<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Service\PythonEmbeddingService;

#[AsCommand(
    name: 'app:process-image',
    description: 'Send an image to the embedding service and search products based on the description',
)]
class ProcessImageCommand extends Command
{
    private PythonEmbeddingService $embeddingGenerator;

    public function __construct(PythonEmbeddingService $embeddingGenerator)
    {
        parent::__construct();
        $this->embeddingGenerator = $embeddingGenerator;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('image', InputArgument::REQUIRED, 'Path to the image file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $imagePath = $input->getArgument('image');

        if (!is_file($imagePath)) {
            $io->error('Image file not found: ' . $imagePath);
            return Command::FAILURE;
        }

        try {
            $description = $this->embeddingGenerator->describeImage($imagePath);

            if ($description === null || $description === '') {
                $io->error('Service response missing description');
                return Command::FAILURE;
            }

            $io->text('Image description: ' . $description);

            $application = $this->getApplication();
            if (!$application) {
                $io->error('Console application not available');
                return Command::FAILURE;
            }

            $command = $application->find('app:test-search');
            $arguments = [
                'command' => 'app:test-search',
                'query' => $description,
            ];
            $testInput = new ArrayInput($arguments);
            $testInput->setInteractive(false);

            return $command->run($testInput, $output);
        } catch (\Throwable $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

