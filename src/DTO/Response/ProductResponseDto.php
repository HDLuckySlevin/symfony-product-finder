<?php

namespace App\DTO\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProductResponseDto',
    properties: [
        new OA\Property(property: 'id', type: 'string', nullable: true),
        new OA\Property(property: 'title', type: 'string', nullable: true),
        new OA\Property(property: 'distance', type: 'number', format: 'float', nullable: true)
    ]
)]
class ProductResponseDto implements \JsonSerializable
{
    public readonly ?string $id;
    public readonly ?string $title;
    public readonly ?float $distance;

    /**
     * Initializes a new immutable ProductResponseDto with optional id, title, and distance values.
     *
     * @param string|null $id The product identifier, or null if not set.
     * @param string|null $title The product title, or null if not set.
     * @param float|null $distance The distance value, or null if not set.
     */
    public function __construct(?string $id = null, ?string $title = null, ?float $distance = null)
    {
        $this->id = $id;
        $this->title = $title;
        $this->distance = $distance;
    }

    /**
     * Creates a new ProductResponseDto instance from an associative array.
     *
     * Extracts the 'id', 'title', and 'distance' keys from the input array, defaulting to null if any are missing.
     *
     * @param array<string, mixed> $data Associative array with optional 'id', 'title', and 'distance' keys.
     * @return self New ProductResponseDto instance populated from the array.
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
     * Returns an associative array representation of the DTO for JSON serialization.
     *
     * @return array<string, mixed> Associative array with keys 'id', 'title', and 'distance'.
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
