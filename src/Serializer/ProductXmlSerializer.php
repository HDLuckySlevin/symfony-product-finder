<?php

namespace App\Serializer;

use App\Entity\Product;

/**
 * Serializer for converting XML product data to Product objects
 * 
 * This class handles the deserialization of XML product data into Product objects.
 * It maps XML nodes to product properties, handles type conversion, and extracts
 * nested data like specifications and features.
 */
class ProductXmlSerializer
{
    /**
     * Map of XML node names to property types
     * 
     * This constant defines how XML nodes should be mapped to Product properties
     * and what data type each property should be converted to.
     * 
     * @var array<string, array<string, string>> Map of XML node names to property types
     */
    private const PROPERTY_MAPPING = [
        'id' => ['type' => 'int'],
        'name' => ['type' => 'string'],
        'sku' => ['type' => 'string'],
        'description' => ['type' => 'string'],
        'brand' => ['type' => 'string'],
        'category' => ['type' => 'string'],
        'price' => ['type' => 'float'],
        'image_url' => ['type' => 'string'],
        'rating' => ['type' => 'float'],
        'stock' => ['type' => 'int'],
    ];

    /**
     * Create a Product object from XML node
     * 
     * Deserializes a SimpleXMLElement node into a Product object by:
     * 1. Mapping basic properties using the PROPERTY_MAPPING configuration
     * 2. Converting values to the appropriate data types
     * 3. Setting the values on the Product object using explicit setter calls
     * 4. Processing nested elements like specifications and features
     * 
     * @param \SimpleXMLElement $productNode The XML node containing product data
     * @return Product A fully populated Product object
     */
    public function deserialize(\SimpleXMLElement $productNode): Product
    {
        $product = new Product();

        // Map basic properties using the property mapping
        foreach (self::PROPERTY_MAPPING as $xmlNode => $mapping) {
            if (isset($productNode->$xmlNode)) {
                $value = $productNode->$xmlNode;
                $type = $mapping['type'];

                // Cast to the appropriate type
                switch ($type) {
                    case 'int':
                        $value = (int)$value;
                        break;
                    case 'float':
                        $value = (float)$value;
                        break;
                    case 'string':
                    default:
                        $value = (string)$value;
                        break;
                }

                // Use explicit setter calls instead of dynamic method calls
                switch ($xmlNode) {
                    case 'id':
                        $product->setId($value);
                        break;
                    case 'name':
                        $product->setName($value);
                        break;
                    case 'sku':
                        $product->setSku($value);
                        break;
                    case 'description':
                        $product->setDescription($value);
                        break;
                    case 'brand':
                        $product->setBrand($value);
                        break;
                    case 'category':
                        $product->setCategory($value);
                        break;
                    case 'price':
                        $product->setPrice($value);
                        break;
                    case 'image_url':
                        $product->setImageUrl($value);
                        break;
                    case 'rating':
                        $product->setRating($value);
                        break;
                    case 'stock':
                        $product->setStock($value);
                        break;
                }
            }
        }

        // Process specifications
        $product->setSpecifications($this->extractSpecifications($productNode));

        // Process features
        $product->setFeatures($this->extractFeatures($productNode));

        return $product;
    }

    /**
     * Extracts all meaningful text chunks and image URLs from a product node.
     *
     * @param \SimpleXMLElement $productNode The XML node containing product data.
     * @param string $productId The product ID, to be associated with each chunk.
     * @param string $productName The product name, to be associated with each chunk.
     * @return array<int, array<string, mixed>> A list of chunks. Each chunk is an array with
     *                                          'product_id', 'product_name', 'type', and 'content'.
     */
    public function extractTextAndImageChunks(\SimpleXMLElement $productNode, string $productId, string $productName): array
    {
        $chunks = [];
        $processedTexts = []; // To avoid duplicate text entries for the same type

        // Helper to add chunk if content is not empty
        $addChunk = function ($type, $content, $originalField = null) use (&$chunks, $productId, $productName, &$processedTexts) {
            $trimmedContent = trim((string)$content);
            if (!empty($trimmedContent)) {
                // For generic fields, avoid adding the same text multiple times if it appears in different simple tags
                // For specific types like 'specification' or 'feature', allow multiple entries as they are distinct items.
                $isGenericType = !in_array($type, ['specification', 'feature', 'image_url']);
                $textKey = $isGenericType ? $type . '_' . $trimmedContent : uniqid($type . '_', true);

                if (!isset($processedTexts[$textKey])) {
                    $chunks[] = [
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'type' => $type,
                        'content' => $trimmedContent,
                        'original_field' => $originalField ?? $type,
                    ];
                    $processedTexts[$textKey] = true;
                }
            }
        };

        // Process simple top-level elements defined in PROPERTY_MAPPING
        foreach (self::PROPERTY_MAPPING as $xmlNode => $mapping) {
            if (isset($productNode->$xmlNode)) {
                $content = (string)$productNode->$xmlNode;
                if ($xmlNode === 'image_url') {
                    if (filter_var($content, FILTER_VALIDATE_URL)) {
                        $addChunk('image_url', $content, $xmlNode);
                    }
                } elseif ($mapping['type'] !== 'skip') { // Add a way to skip certain mapped properties if needed
                    $addChunk($xmlNode, $content, $xmlNode);
                }
            }
        }

        // Process specifications
        if (isset($productNode->specifications) && isset($productNode->specifications->specification)) {
            foreach ($productNode->specifications->specification as $spec) {
                $name = (string)$spec->attributes()->name;
                $value = (string)$spec;
                if (!empty(trim($name)) && !empty(trim($value))) {
                    $addChunk('specification', $name . ': ' . $value, 'specification_' . $name);
                }
            }
        }

        // Process features
        if (isset($productNode->features) && isset($productNode->features->feature)) {
            foreach ($productNode->features->feature as $feature) {
                $addChunk('feature', (string)$feature, 'feature');
            }
        }

        // Process any other direct child elements not in PROPERTY_MAPPING as 'generic'
        // This makes the parser more dynamic to schema variations
        foreach ($productNode->children() as $childNode) {
            $childName = $childNode->getName();
            if (!isset(self::PROPERTY_MAPPING[$childName]) && $childName !== 'specifications' && $childName !== 'features') {
                // If the child has its own children, it might be a structured element we don't want as a single text blob.
                // For now, we only take direct text content of such generic nodes.
                // More complex recursive parsing could be added here if needed.
                if ($childNode->count() == 0) { // Only take text if it's a leaf node
                    $addChunk('generic', (string)$childNode, $childName);
                } elseif (in_array($childName, ['image', 'imageUrl', 'img_url'])) { // common alternative image tags
                     if (filter_var((string)$childNode, FILTER_VALIDATE_URL)) {
                        $addChunk('image_url', (string)$childNode, $childName);
                    }
                }
            }
        }
        return $chunks;
    }

    /**
     * Extract specifications from product node
     * 
     * Parses the specifications section of the XML product node and converts it
     * into an associative array of specification name-value pairs.
     * 
     * @param \SimpleXMLElement $productNode The XML node containing product data
     * @return array<string, string> Associative array of specifications (name => value)
     */
    private function extractSpecifications(\SimpleXMLElement $productNode): array
    {
        $specifications = [];
        if (isset($productNode->specifications) && isset($productNode->specifications->specification)) {
            foreach ($productNode->specifications->specification as $spec) {
                $name = (string)$spec->attributes()->name;
                $value = (string)$spec;
                $specifications[$name] = $value;
            }
        }
        return $specifications;
    }

    /**
     * Extract features from product node
     * 
     * Parses the features section of the XML product node and converts it
     * into an indexed array of feature strings.
     * 
     * @param \SimpleXMLElement $productNode The XML node containing product data
     * @return array<int, string> Indexed array of feature strings
     */
    private function extractFeatures(\SimpleXMLElement $productNode): array
    {
        $features = [];
        if (isset($productNode->features) && isset($productNode->features->feature)) {
            foreach ($productNode->features->feature as $feature) {
                $features[] = (string)$feature;
            }
        }
        return $features;
    }
}
