<?php

namespace App\Serializer;

use App\Entity\Product;

/**
 * Serializer for converting JSON product data to Product objects.
 */
class ProductJsonSerializer
{
    /**
     * Map of JSON keys to property types and setter methods.
     *
     * @var array<string, array{type: string, method: string}>
     */
    private const PROPERTY_MAPPING = [
        'id' => ['type' => 'int', 'method' => 'setId'],
        'title' => ['type' => 'string', 'method' => 'setName'],
        'name' => ['type' => 'string', 'method' => 'setName'],
        'sku' => ['type' => 'string', 'method' => 'setSku'],
        'description' => ['type' => 'string', 'method' => 'setDescription'],
        'brand' => ['type' => 'string', 'method' => 'setBrand'],
        'category' => ['type' => 'string', 'method' => 'setCategory'],
        'price' => ['type' => 'float', 'method' => 'setPrice'],
        'image_url' => ['type' => 'string', 'method' => 'setImageUrl'],
        'rating' => ['type' => 'float', 'method' => 'setRating'],
        'stock' => ['type' => 'int', 'method' => 'setStock'],
    ];

    /**
     * Create a Product object from an associative array.
     *
     * @param array<string, mixed> $data
     */
    public function deserialize(array $data): Product
    {
        $product = new Product();

        foreach (self::PROPERTY_MAPPING as $key => $config) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $method = $config['method'];

                if ($value === null) {
                    $product->$method(null);
                    continue;
                }

                switch ($config['type']) {
                    case 'int':
                        $value = (int) $value;
                        break;
                    case 'float':
                        $value = (float) $value;
                        break;
                    case 'string':
                    default:
                        $value = (string) $value;
                        break;
                }

                $product->$method($value);
            }
        }

        $specifications = null;
        if (
            isset($data['specifications']) &&
            is_array($data['specifications']) &&
            !array_is_list($data['specifications'])
        ) {
            $specifications = [];
            foreach ($data['specifications'] as $name => $value) {
                $specifications[(string) $name] = (string) $value;
            }
        }
        $product->setSpecifications($specifications);

        $features = [];
        if (isset($data['features']) && is_array($data['features'])) {
            foreach ($data['features'] as $feature) {
                $features[] = (string) $feature;
            }
        }
        $product->setFeatures($features);

        return $product;
    }
}
