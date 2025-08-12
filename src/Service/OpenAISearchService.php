<?php

namespace App\Service;

use OpenAI\Client;
use Psr\Log\LoggerInterface;

/**
 * Service for generating search results using OpenAI's Chat API
 * 
 * This service provides methods for generating text completions
 * using OpenAI's chat models for search functionality.
 */
class OpenAISearchService implements SearchServiceInterface
{
    /**
     * OpenAI API client
     */
    private Client $client;

    /**
     * OpenAI chat model to use
     */
    private string $chatModel;

    /**
     * Logger for recording operations and errors
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     * 
     * @param Client $client The OpenAI API client
     * @param LoggerInterface $logger The logger service
     * @param string $chatModel The chat model to use (default: 'gpt-3.5-turbo')
     */
    public function __construct(
        Client $client, 
        LoggerInterface $logger,
        string $chatModel = 'gpt-3.5-turbo'
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->chatModel = $chatModel;
    }

    /**
     * Generate a chat completion for the given messages
     * 
     * @param array<array{role: string, content: string}> $messages Array of message objects with 'role' and 'content' keys
     * @param array<string, mixed> $options Additional options for the API call (temperature, max_tokens, etc.)
     * @return string The generated text response
     * @throws \RuntimeException If the API response format is invalid or if the API call fails
     */
    public function generateChatCompletion(array $messages, array $options = []): string
    {
        $this->logger->info('openai.chat.request', [
            'model' => $this->chatModel,
            'message_count' => count($messages),
            'payload' => [
                'model' => $this->chatModel,
                'messages' => $messages,
            ],
        ]);

        try {
            $requestOptions = array_merge([
                'model' => $this->chatModel,
                'messages' => $messages,
            ], $options);

            $response = $this->client->chat()->create($requestOptions);

            if (isset($response->choices[0]->message->content)) {
                $content = $response->choices[0]->message->content;
                $this->logger->info('openai.chat.response', [
                    'content_length' => strlen($content),
                    'choices' => count($response->choices ?? []),
                ]);
                return $content;
            } else {
                $this->logger->error('openai.chat.invalid_response', [
                    'response' => json_encode($response)
                ]);
                throw new \RuntimeException('Failed to generate chat completion for search: Invalid response format from OpenAI client');
            }
        } catch (\Exception $e) {
            $this->logger->error('openai.chat.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Re-throw the exception
            throw new \RuntimeException('Failed to generate chat completion for search: ' . $e->getMessage(), 0, $e);
        }
    }

    /****
     * Generates a text completion for a single prompt using the OpenAI chat model.
     *
     * @param string $prompt The input prompt to generate a response for.
     * @param array<string, mixed> $options Optional parameters to customize the API call.
     * @return string The generated text response.
     */
    public function generateCompletion(string $prompt, array $options = []): string
    {
        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];

        return $this->generateChatCompletion($messages, $options);
    }
}
