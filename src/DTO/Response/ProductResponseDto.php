<?php

namespace App\DTO\Response;

class ProductResponseDto implements \JsonSerializable
{
    private ?string $id = null;
    private ?string $title = null;
    private ?float $distance = null;

    public function __construct(?string $id = null, ?string $title = null, ?float $distance = null)
    {
        $this->id = $id;
        $this->title = $title;
        $this->distance = $distance;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getDistance(): ?float
    {
        return $this->distance;
    }

    public function setDistance(?float $distance): self
    {
        $this->distance = $distance;
        return $this;
    }

    /**
     * Create a ProductResponseDto from an array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'] ?? null,
            $data['title'] ?? null,
            $data['distance'] ?? null
        );
    }

    /**
     * Convert the DTO to an array for JSON serialization
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'distance' => $this->distance,
        ];
    }
}