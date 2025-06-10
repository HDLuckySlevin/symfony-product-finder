<?php

namespace App\Service;

use OpenAI\Responses\Chat\CreateResponse; // Ensure this is the correct FQCN for the response

interface ChatResourceInterface
{
    public function create(array $params): CreateResponse;
    // Add other methods from OpenAI\Resources\Chat that you might use
}
