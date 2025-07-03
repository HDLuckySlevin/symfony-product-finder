<?php

namespace App\DTO\Request;

use OpenApi\Attributes as OA;

use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'ChatRequestDto',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'smartphone')
    ]
)]
readonly class ChatRequestDto
{
    #[Assert\NotBlank(message: "Message parameter is required")]
    #[Assert\Type("string")]
    public string $message;

    /****
     * Initializes a new ChatRequestDto with the provided message.
     *
     * @param string $message The chat message content. Defaults to an empty string.
     */
    public function __construct(string $message = '')
    {
        $this->message = $message;
    }

}
