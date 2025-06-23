<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

#[AsCommand(
    name: 'app:process-image',
    description: 'Send an image to the embedding service and search products based on the description',
)]
class ProcessImageCommand extends Command
{
    private HttpClientInterface $httpClient;
    private string $embedHost;
    private string $embedPort;

    public function __construct(HttpClientInterface $httpClient, string $embedHost, string $embedPort)
    {
        parent::__construct();
        $this->httpClient = $httpClient;
        $this->embedHost = $embedHost;
        $this->embedPort = $embedPort;
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

        $endpoint = rtrim($this->embedHost, '/') . ':' . $this->embedPort . '/image-embedding';

        try {
            $dataPart = new DataPart(file_get_contents($imagePath), basename($imagePath));
            $formData = new FormDataPart(['file' => $dataPart]);

            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
            ]);

            if ($response->getStatusCode() !== 200) {
                $io->error('Failed to process image: HTTP ' . $response->getStatusCode());
                return Command::FAILURE;
            }

            $data = $response->toArray(false);
            if (!isset($data['description'])) {
                $io->error('Service response missing description');
                return Command::FAILURE;
            }

            $description = $data['description'];
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

