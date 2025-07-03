<?php

namespace App\Service;

use App\Entity\Product;
use App\Serializer\ProductJsonSerializer;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service to import and validate a product from JSON data.
 */
class JsonImportService
{
    private ValidatorInterface $validator;
    private ProductJsonSerializer $serializer;

    public function __construct(
        ValidatorInterface $validator,
        ProductJsonSerializer $serializer
    ) {
        $this->validator = $validator;
        $this->serializer = $serializer;
    }

    /**
     * Import product from JSON string.
     */
    public function importFromString(string $jsonContent): Product
    {
        $data = json_decode($jsonContent, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON format');
        }
        return $this->importFromArray($data);
    }

    /**
     * Import product from associative array.
     *
     * @param array<string, mixed> $data
     */
    public function importFromArray(array $data): Product
    {
        $product = $this->serializer->deserialize($data);
        $this->validateProduct($product);
        return $product;
    }

    private function validateProduct(Product $product): void
    {
        $violations = $this->validator->validate($product);
        if (count($violations) > 0) {
            throw new \RuntimeException($this->formatViolations($violations));
        }
    }

    private function formatViolations(ConstraintViolationListInterface $violations): string
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage());
        }

        return 'Product validation failed: ' . implode(', ', $errors);
    }
}
