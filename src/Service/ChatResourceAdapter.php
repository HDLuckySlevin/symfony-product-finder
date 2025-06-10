<?php

namespace App\Service;

use OpenAI\Resources\Chat as ActualChatResource;
use OpenAI\Responses\Chat\CreateResponse; // Ensure this is correct

class ChatResourceAdapter implements ChatResourceInterface
{
    private ActualChatResource $adaptee;

    public function __construct(ActualChatResource $adaptee)
    {
        $this->adaptee = $adaptee;
    }

    public function create(array $params): CreateResponse
    {
        return $this->adaptee->create($params);
    }
}
