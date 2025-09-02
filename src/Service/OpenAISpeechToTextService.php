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
            $this->logger->info('openai.stt.request', [
                'model' => $this->model,
                'path' => $audioPath,
                'size' => filesize($audioPath),
                'payload' => [
                    'model' => $this->model,
                    'file' => basename($audioPath),
                    'response_format' => 'json',
                ],
            ]);

            $response = $this->client->audio()->transcribe([
                'model' => $this->model,
                'file' => fopen($audioPath, 'r'),
                'response_format' => 'json',
            ]);

            if (isset($response->text)) {
                $this->logger->info('openai.stt.response', [
                    'length' => strlen($response->text),
                ]);
                return $response->text;
            }

            $this->logger->error('openai.stt.invalid_response', ['response' => $response]);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('openai.stt.error', [
                'exception' => $e,
            ]);
            return null;
        }
    }
}

