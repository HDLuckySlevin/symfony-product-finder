<?php

namespace App\DTO\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ChatResponseDto',
    properties: [
        new OA\Property(property: 'success', type: 'boolean'),
        new OA\Property(property: 'query', type: 'string', nullable: true),
        new OA\Property(property: 'message', type: 'string', nullable: true),
        new OA\Property(property: 'response', type: 'string', nullable: true),
        new OA\Property(
            property: 'products',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/ProductResponseDto')
        )
    ]
)]
readonly class ChatResponseDto implements \JsonSerializable
{
    public bool $success;
    public ?string $query;
    public ?string $message;
    public ?string $response;
    /** @var ProductResponseDto[] */
    public array $products;

    /**
     * Initializes a new immutable ChatResponseDto with the provided chat response data.
     *
     * @param bool $success Indicates whether the chat response was successful.
     * @param string|null $query Optional query string associated with the chat.
     * @param string|null $message Optional message to include in the response.
     * @param string|null $response Optional response content.
     * @param ProductResponseDto[] $products Array of product response DTOs related to the chat.
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
     * Returns an associative array representation of the chat response for JSON serialization.
     *
     * The resulting array always includes the keys 'success', 'query', 'response', and 'products'.
     * The 'message' key is included only if the message property is not null.
     *
     * @return array<string, mixed> Associative array suitable for JSON encoding.
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
