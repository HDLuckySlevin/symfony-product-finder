<?php

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

class SearchRequestDto
{
    #[Assert\NotBlank(message: "Query parameter is required")]
    #[Assert\Type("string")]
    private string $query;

    public function __construct(string $query = '')
    {
        $this->query = $query;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function setQuery(string $query): self
    {
        $this->query = $query;
        return $this;
    }
}
