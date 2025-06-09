<?php

namespace App\Service;

/**
 * Interface for generating results using OpenAI's Chat API
 * 
 * This interface defines methods for generating text completions
 * using OpenAI's chat models.
 */
interface SearchServiceInterface
{
    /**
     * Generate a chat completion for the given messages
     * 
     * @param array<array{role: string, content: string}> $messages Array of message objects with 'role' and 'content' keys
     * @param array<string, mixed> $options Additional options for the API call (temperature, max_tokens, etc.)
     * @return string The generated text response
     * @throws \RuntimeException If the API response format is invalid or if the API call fails
     */
    public function generateChatCompletion(array $messages, array $options = []): string;

    /**
     * Generate a simple text completion for a single prompt
     * 
     * @param string $prompt The text prompt
     * @param array<string, mixed> $options Additional options for the API call
     * @return string The generated text response
     */
    public function generateCompletion(string $prompt, array $options = []): string;
}
