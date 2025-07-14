<?php

namespace App\Service;

use OpenAI\Client;
use Psr\Log\LoggerInterface;

/**
 * Service for performing speech-to-text using OpenAI Whisper API.
 */
class OpenAISpeechToTextService implements SpeechToTextServiceInterface
{
    private Client $client;
    private LoggerInterface $logger;
    private string $model;

    public function __construct(Client $client, LoggerInterface $logger, string $model = 'whisper-1')
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->model = $model;
    }

    public function transcribe(string $audioPath): ?string
    {
        if (!is_file($audioPath)) {
            $this->logger->error('Audio file not found for transcription', ['path' => $audioPath]);
            return null;
        }

        try {
            $this->logger->info('Sending audio file to OpenAI Whisper', [
                'model' => $this->model,
                'path' => $audioPath,
                'size' => filesize($audioPath),
            ]);
            $this->logger->debug('Audio file details', [
                'mime' => mime_content_type($audioPath) ?: 'unknown',
                'extension' => pathinfo($audioPath, PATHINFO_EXTENSION),
            ]);

            $response = $this->client->audio()->transcribe([
                'model' => $this->model,
                'file' => fopen($audioPath, 'r'),
                'response_format' => 'json',
            ]);

            if (isset($response->text)) {
                $this->logger->info('Received transcription from OpenAI Whisper', [
                    'length' => strlen($response->text),
                ]);
                return $response->text;
            }

            $this->logger->error('Invalid response format from OpenAI Whisper', ['response' => $response]);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Error during OpenAI Whisper transcription', [
                'message' => $e->getMessage(),
                'path' => $audioPath,
            ]);
            $this->logger->debug('Whisper exception', ['exception' => $e]);
            return null;
        }
    }
}

