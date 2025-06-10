<?php

namespace App\Service;

interface OpenAIClientInterface
{
    public function chat(): ChatResourceInterface;
    // Add other methods from OpenAI\Client that you might need to use and mock
}
