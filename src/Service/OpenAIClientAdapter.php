<?php

namespace App\Service;

use OpenAI\Client as ActualOpenAIClient;

class OpenAIClientAdapter implements OpenAIClientInterface
{
    private ActualOpenAIClient $adaptee;

    public function __construct(ActualOpenAIClient $adaptee)
    {
        $this->adaptee = $adaptee;
    }

    public function chat(): ChatResourceInterface
    {
        // Wrap the actual chat resource in an adapter as well
        return new ChatResourceAdapter($this->adaptee->chat());
    }
}
