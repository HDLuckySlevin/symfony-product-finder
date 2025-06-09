<?php

namespace App\DTO\Response;

readonly class ChatResponseDto implements \JsonSerializable
{
    public bool $success;
    public ?string $query;
    public ?string $message;
    public ?string $response;
    /** @var ProductResponseDto[] */
    public array $products;

    /**
     * @param ProductResponseDto[] $products
     */
    public function __construct(
        bool $success = true,
        ?string $query = null,
        ?string $message = null,
        ?string $response = null,
        array $products = []
    ) {
        $this->success = $success;
        $this->query = $query;
        $this->message = $message;
        $this->response = $response;
        $this->products = $products;
    }

    /**
     * Convert the DTO to an array for JSON serialization
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $result = [
            'success' => $this->success,
            'query' => $this->query,
            'response' => $this->response,
            'products' => $this->products,
        ];

        if ($this->message !== null) {
            $result['message'] = $this->message;
        }

        return $result;
    }
}
