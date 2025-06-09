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
     * @param array $messages Array of message objects with 'role' and 'content' keys
     * @param array $options Additional options for the API call (temperature, max_tokens, etc.)
     * @return string The generated text response
     * @throws \RuntimeException If the API response format is invalid or if the API call fails
     */
    public function generateChatCompletion(array $messages, array $options = []): string
    {
        $this->logger->info('Generating chat completion for search', [
            'model' => $this->chatModel,
            'message_count' => count($messages)
        ]);

        try {
            $requestOptions = array_merge([
                'model' => $this->chatModel,
                'messages' => $messages,
            ], $options);

            $response = $this->client->chat()->create($requestOptions);

            if (isset($response->choices[0]->message->content)) {
                $content = $response->choices[0]->message->content;
                $this->logger->debug('Successfully received chat completion from OpenAI for search', [
                    'content_length' => strlen($content)
                ]);
                return $content;
            } else {
                $this->logger->error('Invalid response format from OpenAI client', [
                    'response' => json_encode($response)
                ]);
                throw new \RuntimeException('Failed to generate chat completion for search: Invalid response format from OpenAI client');
            }
        } catch (\Exception $e) {
            $this->logger->error('Error generating chat completion with OpenAI API for search', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Re-throw the exception
            throw new \RuntimeException('Failed to generate chat completion for search: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate a simple text completion for a single prompt
     * 
     * @param string $prompt The text prompt
     * @param array $options Additional options for the API call
     * @return string The generated text response
     */
    public function generateCompletion(string $prompt, array $options = []): string
    {
        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];

        return $this->generateChatCompletion($messages, $options);
    }
}