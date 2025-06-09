<?php

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

class ChatRequestDto
{
    #[Assert\NotBlank(message: "Message parameter is required")]
    #[Assert\Type("string")]
    private string $message;

    #[Assert\Type("array")]
    private array $history;

    public function __construct(string $message = '', array $history = [])
    {
        $this->message = $message;
        $this->history = $history;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    public function getHistory(): array
    {
        return $this->history;
    }

    public function setHistory(array $history): void
    {
        $this->history = $history;
    }
}
