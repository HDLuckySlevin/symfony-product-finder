<?php

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

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
