<?php

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

readonly class ChatRequestDto
{
    #[Assert\NotBlank(message: "Message parameter is required")]
    #[Assert\Type("string")]
    public string $message;

    /**
     * @param string $message
     */
    public function __construct(string $message = '')
    {
        $this->message = $message;
    }

}
