<?php

namespace App\Command;

use App\Service\SpeechToTextServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-audio',
    description: 'Transcribe an audio file and search products based on the text',
)]
class ProcessAudioCommand extends Command
{
    private SpeechToTextServiceInterface $sttService;
    private LoggerInterface $logger;

    public function __construct(SpeechToTextServiceInterface $sttService, LoggerInterface $logger)
    {
        parent::__construct();
        $this->sttService = $sttService;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('audio', InputArgument::REQUIRED, 'Path to the audio file')
            ->addOption('simple', null, null, 'Reduce output of the underlying search command');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $audioPath = $input->getArgument('audio');
        $simple = (bool) $input->getOption('simple');

        $this->logger->info('ProcessAudioCommand started', ['path' => $audioPath]);

        if (!is_file($audioPath)) {
            $io->error('Audio file not found: ' . $audioPath);
            $this->logger->error('Audio file not found', ['path' => $audioPath]);
            return Command::FAILURE;
        }

        try {
            $text = $this->sttService->transcribe($audioPath);

            if ($text === null || $text === '') {
                $this->logger->error('Transcription failed or empty');
                $io->error('Transcription failed or empty');
                return Command::FAILURE;
            }

            $this->logger->info('Transcription succeeded', ['text' => $text]);

            if (!$simple) {
                $io->text('Transcribed text: ' . $text);
            }

            $application = $this->getApplication();
            if (!$application) {
                $io->error('Console application not available');
                return Command::FAILURE;
            }

            $command = $application->find('app:test-search');
            $arguments = [
                'command' => 'app:test-search',
                'query' => $text,
            ];
            if ($simple) {
                $arguments['--simple'] = true;
            }
            $testInput = new ArrayInput($arguments);
            $testInput->setInteractive(false);

            return $command->run($testInput, $output);
        } catch (\Throwable $e) {
            $this->logger->error('Error running ProcessAudioCommand', ['exception' => $e]);
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

