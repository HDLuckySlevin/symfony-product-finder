<?php

namespace App\Service;

use OpenAI\Client as VendorOpenAIClient; // Alias the vendor client

/**
 * Adapter for the OpenAI PHP client.
 *
 * This class implements our OpenAIClientInterface and wraps the actual
 * OpenAI client from the vendor library. This allows us to use the
 * official client while adhering to our defined interface, facilitating
 * dependency injection and testing.
 */
class OpenAIClientAdapter implements OpenAIClientInterface
{
    private VendorOpenAIClient $client;

    /**
     * OpenAIClientAdapter constructor.
     *
     * @param VendorOpenAIClient $client The actual OpenAI client instance.
     */
    public function __construct(VendorOpenAIClient $client)
    {
        $this->client = $client;
    }

    /**
     * Access the chat completion functionalities of the OpenAI API.
     *
     * Delegates the call to the wrapped OpenAI client instance.
     *
     * @return object An object that provides access to chat creation
     *                (e.g., OpenAI\Resources\Chat).
     */
    public function chat(): object
    {
        return $this->client->chat();
    }
}
