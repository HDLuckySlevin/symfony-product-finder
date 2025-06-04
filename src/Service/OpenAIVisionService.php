<?php

namespace App\Service;

use OpenAI\Client;
use OpenAI\Exception\ErrorException; // For catching API errors

/**
 * Service for interacting with the OpenAI GPT-4o API for image processing.
 */
class OpenAIVisionService
{
    /**
     * @var Client The OpenAI API client.
     */
    private Client $openAiClient;

    /**
     * OpenAIVisionService constructor.
     *
     * @param Client $openAiClient The OpenAI API client (expected to be pre-configured with the API key).
     */
    public function __construct(Client $openAiClient)
    {
        $this->openAiClient = $openAiClient;
    }

    /**
     * Gets a description for an image using the OpenAI API.
     *
     * @param string $imagePath The path to the image file.
     * @param string $userPrompt The user-provided prompt.
     * @return string The description of the image.
     * @throws \InvalidArgumentException If the image file does not exist or is not readable.
     * @throws \RuntimeException If there's an error reading the image or interacting with the OpenAI API.
     */
    public function getDescriptionForImage(string $imagePath, string $userPrompt): string
    {
        if (!file_exists($imagePath) || !is_readable($imagePath)) {
            throw new \InvalidArgumentException("Image file not found or not readable at path: {$imagePath}");
        }

        $imageContent = @file_get_contents($imagePath);
        if ($imageContent === false) {
            throw new \RuntimeException("Failed to read image content from path: {$imagePath}");
        }

        $base64Image = base64_encode($imageContent);

        $imageMimeType = mime_content_type($imagePath);
        if (!$imageMimeType) {
            // Fallback or throw error if mime type can't be determined
            // For simplicity, defaulting to jpeg if detection fails.
            // A more robust solution would check common extensions or use a library.
            $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
            switch ($extension) {
                case 'png':
                    $imageMimeType = 'image/png';
                    break;
                case 'gif':
                    $imageMimeType = 'image/gif';
                    break;
                case 'webp':
                    $imageMimeType = 'image/webp';
                    break;
                case 'jpg':
                case 'jpeg':
                default:
                    $imageMimeType = 'image/jpeg';
                    break;
            }
        }

        $imageUrl = "data:{$imageMimeType};base64,{$base64Image}";

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $userPrompt,
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $imageUrl,
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->openAiClient->chat()->create([
                'model' => 'gpt-4o',
                'messages' => $messages,
                'max_tokens' => 300, // Optional: Adjust as needed
            ]);

            if (!isset($response->choices[0]->message->content)) {
                throw new \RuntimeException('Unexpected API response structure.');
            }

            return $response->choices[0]->message->content;
        } catch (ErrorException $e) {
            // Catch specific OpenAI client exceptions
            throw new \RuntimeException("OpenAI API Error: " . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            // Catch any other exceptions during the process
            throw new \RuntimeException("Error processing image: " . $e->getMessage(), 0, $e);
        }
    }
}
