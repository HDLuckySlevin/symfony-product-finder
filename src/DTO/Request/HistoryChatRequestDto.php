<?php

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

readonly class HistoryChatRequestDto extends ChatRequestDto
{
    /**
     * @var array<int, array{role: string, content: string}>
     */
    #[Assert\All([
        new Assert\Collection(
            fields: [
                'role' => new Assert\NotBlank(),
                'content' => new Assert\NotBlank(),
            ],
            allowExtraFields: true
        )
    ])]
    public array $history;

    public function __construct(string $message = '', array $history = [])
    {
        parent::__construct($message);
        $this->history = $history;
    }
}
