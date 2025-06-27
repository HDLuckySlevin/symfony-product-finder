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
            $this->logger->info('Sending audio file to OpenAI Whisper', ['model' => $this->model]);

            $response = $this->client->audio()->transcribe([
                'model' => $this->model,
                'file' => fopen($audioPath, 'r'),
                'response_format' => 'json',
            ]);

            if (is_array($response) && isset($response['text'])) {
                return $response['text'];
            }

            if (is_object($response) && isset($response->text)) {
                return $response->text;
            }

            $this->logger->error('Invalid response format from OpenAI Whisper', ['response' => $response]);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Error during OpenAI Whisper transcription', [
                'exception' => $e,
            ]);
            return null;
        }
    }
}

