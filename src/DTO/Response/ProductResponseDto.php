<?php

namespace App\DTO\Response;

class ProductResponseDto implements \JsonSerializable
{
    public readonly ?string $id;
    public readonly ?string $title;
    public readonly ?float $distance;

    public function __construct(?string $id = null, ?string $title = null, ?float $distance = null)
    {
        $this->id = $id;
        $this->title = $title;
        $this->distance = $distance;
    }

    /**
     * Create a ProductResponseDto from an array
     * 
     * @param array<string, mixed> $data
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
     * 
     * @return array<string, mixed>
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
