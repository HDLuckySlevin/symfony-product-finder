<?php

namespace App\Tests;

use App\Entity\Product;
use App\Serializer\ProductXmlSerializer;
use App\Service\XmlImportService;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class XmlImportServiceTest extends TestCase
{
    private XmlImportService $xmlImportService;
    private ValidatorInterface $validator;
    private ProductXmlSerializer $productXmlSerializer;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->productXmlSerializer = $this->createMock(ProductXmlSerializer::class);

        // Configure the validator mock to return an empty violation list (valid)
        $this->validator->method('validate')
            ->willReturn(new ConstraintViolationList());

        // Configure the ProductXmlSerializer mock to return a Product object
        $this->productXmlSerializer->method('deserialize')
            ->willReturnCallback(function (\SimpleXMLElement $productNode) {
                $product = new Product();
                if (isset($productNode->id)) $product->setId((int)$productNode->id);
                if (isset($productNode->name)) $product->setName((string)$productNode->name);
                if (isset($productNode->sku)) $product->setSku((string)$productNode->sku);
                if (isset($productNode->description)) $product->setDescription((string)$productNode->description);
                if (isset($productNode->brand)) $product->setBrand((string)$productNode->brand);
                if (isset($productNode->category)) $product->setCategory((string)$productNode->category);
                if (isset($productNode->price)) $product->setPrice((float)$productNode->price);

                // Handle specifications
                $specifications = [];
                if (isset($productNode->specifications) && isset($productNode->specifications->specification)) {
                    foreach ($productNode->specifications->specification as $spec) {
                        $name = (string)$spec->attributes()->name;
                        $value = (string)$spec;
                        $specifications[$name] = $value;
                    }
                }
                $product->setSpecifications($specifications);

                // Handle features
                $features = [];
                if (isset($productNode->features) && isset($productNode->features->feature)) {
                    foreach ($productNode->features->feature as $feature) {
                        $features[] = (string)$feature;
                    }
                }
                $product->setFeatures($features);

                return $product;
            });

        $this->xmlImportService = new XmlImportService(
            $this->validator,
            $this->productXmlSerializer
        );
    }

    public function testImportFromString(): void
    {
        $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<products>
    <product>
        <id>1</id>
        <name>Test Product</name>
        <sku>TEST-123</sku>
        <description>Test description</description>
        <brand>Test Brand</brand>
        <category>Test Category</category>
        <price>99.99</price>
        <specifications>
            <specification name="color">Black</specification>
            <specification name="weight">200g</specification>
        </specifications>
        <features>
            <feature>Feature 1</feature>
            <feature>Feature 2</feature>
        </features>
    </product>
</products>
XML;

        // Verify that the ProductXmlSerializer's deserialize method is called
        $this->productXmlSerializer->expects($this->once())
            ->method('deserialize')
            ->with($this->isInstanceOf(\SimpleXMLElement::class));

        // Verify that the validator is called for each product
        $this->validator->expects($this->once())
            ->method('validate')
            ->with($this->isInstanceOf(Product::class));

        $products = $this->xmlImportService->importFromString($xmlContent);

        $this->assertCount(1, $products);
        $this->assertInstanceOf(Product::class, $products[0]);
        $this->assertEquals(1, $products[0]->getId());
        $this->assertEquals('Test Product', $products[0]->getName());
        $this->assertEquals('TEST-123', $products[0]->getSku());
        $this->assertEquals('Test description', $products[0]->getDescription());
        $this->assertEquals('Test Brand', $products[0]->getBrand());
        $this->assertEquals('Test Category', $products[0]->getCategory());
        $this->assertEquals(99.99, $products[0]->getPrice());

        $specifications = $products[0]->getSpecifications();
        $this->assertCount(2, $specifications);
        $this->assertEquals('Black', $specifications['color']);
        $this->assertEquals('200g', $specifications['weight']);

        $features = $products[0]->getFeatures();
        $this->assertCount(2, $features);
        $this->assertEquals('Feature 1', $features[0]);
        $this->assertEquals('Feature 2', $features[1]);
    }

    public function testImportFromStringWithInvalidXml(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Error processing XML');

        $invalidXml = '<invalid>';
        $this->xmlImportService->importFromString($invalidXml);
    }

    public function testImportFromFileWithNonExistentFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('XML file not found');

        $this->xmlImportService->importFromFile('non_existent_file.xml');
    }
}
