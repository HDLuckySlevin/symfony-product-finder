<?php

namespace App\Service;

/**
 * Interface for OpenAI API client implementations.
 * This allows for mocking or swapping out the actual OpenAI client.
 */
interface OpenAIClientInterface
{
    /**
     * Access the chat completion functionalities of the OpenAI API.
     *
     * This method should return an object that has a `create` method
     * for sending chat requests. The structure of the returned object
     * and its `create` method should align with how the official
     * OpenAI PHP client (or any other client library being used) works.
     *
     * @return object An object that provides access to chat creation.
     *                For example, if using the official 'openai-php/client',
     *                this might return an instance of `OpenAI\Resources\Chat`.
     */
    public function chat(): object;
}
