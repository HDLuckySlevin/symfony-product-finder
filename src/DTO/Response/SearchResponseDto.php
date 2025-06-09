<?php

namespace App\DTO\Response;

class SearchResponseDto implements \JsonSerializable
{
    private bool $success;
    private ?string $query = null;
    private ?string $message = null;
    private array $products = [];

    public function __construct(bool $success = true, ?string $query = null, ?string $message = null, array $products = [])
    {
        $this->success = $success;
        $this->query = $query;
        $this->message = $message;
        $this->products = $products;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): self
    {
        $this->success = $success;
        return $this;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }

    public function setQuery(?string $query): self
    {
        $this->query = $query;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;
        return $this;
    }

    /**
     * @return ProductResponseDto[]
     */
    public function getProducts(): array
    {
        return $this->products;
    }

    /**
     * @param ProductResponseDto[] $products
     */
    public function setProducts(array $products): self
    {
        $this->products = $products;
        return $this;
    }

    /**
     * Add a product to the response
     */
    public function addProduct(ProductResponseDto $product): self
    {
        $this->products[] = $product;
        return $this;
    }

    /**
     * Convert the DTO to an array for JSON serialization
     */
    public function jsonSerialize(): array
    {
        $result = [
            'success' => $this->success,
            'query' => $this->query,
            'products' => $this->products,
        ];

        if ($this->message !== null) {
            $result['message'] = $this->message;
        }

        return $result;
    }
}