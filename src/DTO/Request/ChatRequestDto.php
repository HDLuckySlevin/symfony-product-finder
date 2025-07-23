<?php

namespace App\DTO\Request;

use OpenApi\Attributes as OA;

use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'ChatRequestDto',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'smartphone'),
        new OA\Property(property: 'use_history', type: 'boolean', example: false)
    ]
)]
readonly class ChatRequestDto
{
    #[Assert\NotBlank(message: "Message parameter is required")]
    #[Assert\Type("string")]
    public string $message;

    #[Assert\Type("bool")]
    public bool $use_history;

    /****
     * Initializes a new ChatRequestDto with the provided message.
     *
     * @param string $message The chat message content. Defaults to an empty string.
     */
    public function __construct(string $message = '', bool $use_history = false)
    {
        $this->message = $message;
        $this->use_history = $use_history;
    }

}
