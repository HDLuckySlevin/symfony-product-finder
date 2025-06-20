<?php

namespace App\Entity;

/**
 * Product entity class
 * 
 * Represents a product in the system with all its attributes and metadata.
 * This class is used for storing product information, serialization/deserialization,
 * and generating embeddings for vector search.
 */
class Product
{
    /**
     * Unique identifier for the product
     */
    private ?int $id = null;

    /**
     * Name of the product
     */
    private ?string $name = null;

    /**
     * Stock Keeping Unit - unique product identifier
     */
    private ?string $sku = null;

    /**
     * Detailed description of the product
     */
    private ?string $description = null;

    /**
     * Brand name of the product
     */
    private ?string $brand = null;

    /**
     * Category the product belongs to
     */
    private ?string $category = null;

    /**
     * Price of the product
     */
    private ?float $price = null;

    /**
     * Technical specifications of the product as key-value pairs
     * 
     * @var array<string, string>
     */
    private ?array $specifications = [];

    /**
     * List of product features
     * 
     * @var array<int, string>
     */
    private ?array $features = [];

    /**
     * URL to the product image
     */
    private ?string $imageUrl = null;

    /**
     * Customer rating of the product
     */
    private ?float $rating = null;

    /**
     * Available stock quantity
     */
    private ?int $stock = null;

    /**
     * Vector embeddings for the product used in similarity search
     * 
     * @var array<int, float>|null
     */
    private ?array $embeddings = null;

    /**
     * Vector embeddings for the product image
     *
     * @var array<int, float>|null
     */
    private ?array $imageEmbeddings = null;

    /**
     * Get the product ID
     * 
     * @return int|null The product ID or null if not set
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set the product ID
     * 
     * @param int|null $id The product ID
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    /**
     * Get the product name
     * 
     * @return string|null The product name or null if not set
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set the product name
     * 
     * @param string|null $name The product name
     */
    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    /**
     * Get the product SKU (Stock Keeping Unit)
     * 
     * @return string|null The product SKU or null if not set
     */
    public function getSku(): ?string
    {
        return $this->sku;
    }

    /**
     * Set the product SKU (Stock Keeping Unit)
     * 
     * @param string|null $sku The product SKU
     */
    public function setSku(?string $sku): void
    {
        $this->sku = $sku;
    }

    /**
     * Get the product description
     * 
     * @return string|null The product description or null if not set
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Set the product description
     * 
     * @param string|null $description The product description
     */
    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    /**
     * Get the product brand
     * 
     * @return string|null The product brand or null if not set
     */
    public function getBrand(): ?string
    {
        return $this->brand;
    }

    /**
     * Set the product brand
     * 
     * @param string|null $brand The product brand
     */
    public function setBrand(?string $brand): void
    {
        $this->brand = $brand;
    }

    /**
     * Get the product category
     * 
     * @return string|null The product category or null if not set
     */
    public function getCategory(): ?string
    {
        return $this->category;
    }

    /**
     * Set the product category
     * 
     * @param string|null $category The product category
     */
    public function setCategory(?string $category): void
    {
        $this->category = $category;
    }

    /**
     * Get the product price
     * 
     * @return float|null The product price or null if not set
     */
    public function getPrice(): ?float
    {
        return $this->price;
    }

    /**
     * Set the product price
     * 
     * @param float|null $price The product price
     */
    public function setPrice(?float $price): void
    {
        $this->price = $price;
    }

    /**
     * Get the product specifications
     * 
     * @return array<string, string>|null The product specifications or null if not set
     */
    public function getSpecifications(): ?array
    {
        return $this->specifications;
    }

    /**
     * Set the product specifications
     * 
     * @param array<string, string>|null $specifications The product specifications as key-value pairs
     */
    public function setSpecifications(?array $specifications): void
    {
        $this->specifications = $specifications;
    }

    /**
     * Get the product features
     * 
     * @return array<int, string>|null The product features or null if not set
     */
    public function getFeatures(): ?array
    {
        return $this->features;
    }

    /**
     * Set the product features
     * 
     * @param array<int, string>|null $features The product features
     */
    public function setFeatures(?array $features): void
    {
        $this->features = $features;
    }

    /**
     * Get the product image URL
     * 
     * @return string|null The product image URL or null if not set
     */
    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    /**
     * Set the product image URL
     * 
     * @param string|null $imageUrl The product image URL
     */
    public function setImageUrl(?string $imageUrl): void
    {
        $this->imageUrl = $imageUrl;
    }

    /**
     * Get the product rating
     * 
     * @return float|null The product rating or null if not set
     */
    public function getRating(): ?float
    {
        return $this->rating;
    }

    /**
     * Set the product rating
     * 
     * @param float|null $rating The product rating
     */
    public function setRating(?float $rating): void
    {
        $this->rating = $rating;
    }

    /**
     * Get the product stock quantity
     * 
     * @return int|null The product stock quantity or null if not set
     */
    public function getStock(): ?int
    {
        return $this->stock;
    }

    /**
     * Set the product stock quantity
     * 
     * @param int|null $stock The product stock quantity
     */
    public function setStock(?int $stock): void
    {
        $this->stock = $stock;
    }

    /**
     * Get the product embeddings vector
     * 
     * @return array<int, float>|null The product embeddings or null if not set
     */
    public function getEmbeddings(): ?array
    {
        return $this->embeddings;
    }

    /**
     * Set the product embeddings vector
     * 
     * @param array<int, float>|null $embeddings The product embeddings
     */
    public function setEmbeddings(?array $embeddings): void
    {
        $this->embeddings = $embeddings;
    }

    /**
     * Get the product image embeddings vector
     *
     * @return array<int, float>|null The product image embeddings or null if not set
     */
    public function getImageEmbeddings(): ?array
    {
        return $this->imageEmbeddings;
    }

    /**
     * Set the product image embeddings vector
     *
     * @param array<int, float>|null $imageEmbeddings The product image embeddings
     */
    public function setImageEmbeddings(?array $imageEmbeddings): void
    {
        $this->imageEmbeddings = $imageEmbeddings;
    }
}
