<?php

namespace App\Tests\Serializer;

use App\Serializer\ProductXmlSerializer;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class ProductXmlSerializerTest extends TestCase
{
    private ProductXmlSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new ProductXmlSerializer();
    }

    public function testExtractTextAndImageChunksBasicProduct()
    {
        $xmlString = <<<XML
<product>
    <id>1</id>
    <name>Test Product</name>
    <description>This is a test description.</description>
    <brand>TestBrand</brand>
    <category>TestCategory</category>
    <price>99.99</price>
    <image_url>http://example.com/image.jpg</image_url>
    <custom_field>Custom Value</custom_field>
</product>
XML;
        $productNode = new SimpleXMLElement($xmlString);
        $productId = "1";
        $productName = "Test Product";

        $chunks = $this->serializer->extractTextAndImageChunks($productNode, $productId, $productName);

        $this->assertCount(8, $chunks); // id, name, description, brand, category, price, image_url, custom_field

        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'name', 'content' => 'Test Product', 'original_field' => 'name'
        ], $chunks);
        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'description', 'content' => 'This is a test description.', 'original_field' => 'description'
        ], $chunks);
        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'brand', 'content' => 'TestBrand', 'original_field' => 'brand'
        ], $chunks);
        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'image_url', 'content' => 'http://example.com/image.jpg', 'original_field' => 'image_url'
        ], $chunks);
        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'generic', 'content' => 'Custom Value', 'original_field' => 'custom_field'
        ], $chunks);
         $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'price', 'content' => '99.99', 'original_field' => 'price'
        ], $chunks);
    }

    public function testExtractTextAndImageChunksWithSpecificationsAndFeatures()
    {
        $xmlString = <<<XML
<product>
    <id>2</id>
    <name>Complex Product</name>
    <specifications>
        <specification name="color">Blue</specification>
        <specification name="size">Large</specification>
    </specifications>
    <features>
        <feature>Feature A</feature>
        <feature>Feature B</feature>
        <feature>  </feature>
    </features>
    <image>http://example.com/another_image.png</image>
</product>
XML;
        $productNode = new SimpleXMLElement($xmlString);
        $productId = "2";
        $productName = "Complex Product";

        $chunks = $this->serializer->extractTextAndImageChunks($productNode, $productId, $productName);

        // Expected: id, name, spec1, spec2, feature1, feature2, image
        $this->assertCount(7, $chunks);

        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'specification', 'content' => 'color: Blue', 'original_field' => 'specification_color'
        ], $chunks);
        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'specification', 'content' => 'size: Large', 'original_field' => 'specification_size'
        ], $chunks);
        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'feature', 'content' => 'Feature A', 'original_field' => 'feature'
        ], $chunks);
        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'feature', 'content' => 'Feature B', 'original_field' => 'feature'
        ], $chunks);
        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'image_url', 'content' => 'http://example.com/another_image.png', 'original_field' => 'image'
        ], $chunks);

        // Check that empty feature was skipped
        $featureContents = array_map(function($chunk) { return $chunk['content']; }, array_filter($chunks, function($chunk) { return $chunk['type'] === 'feature'; }));
        $this->assertNotContains('  ', $featureContents);
    }

    public function testExtractTextAndImageChunksSkipsEmptyFields()
    {
        $xmlString = <<<XML
<product>
    <id>3</id>
    <name>Product With Empty Fields</name>
    <description> </description>
    <brand></brand>
    <empty_custom_field>  </empty_custom_field>
    <image_url>http://example.com/image.jpg</image_url>
</product>
XML;
        $productNode = new SimpleXMLElement($xmlString);
        $productId = "3";
        $productName = "Product With Empty Fields";

        $chunks = $this->serializer->extractTextAndImageChunks($productNode, $productId, $productName);

        // Expected: id, name, image_url (description, brand, empty_custom_field should be skipped)
        $this->assertCount(3, $chunks);
        $this->assertContainsEquals(['product_id' => $productId, 'product_name' => $productName, 'type' => 'name', 'content' => 'Product With Empty Fields', 'original_field' => 'name'], $chunks);
        $this->assertContainsEquals(['product_id' => $productId, 'product_name' => $productName, 'type' => 'image_url', 'content' => 'http://example.com/image.jpg', 'original_field' => 'image_url'], $chunks);

        $types = array_column($chunks, 'type');
        $this->assertNotContains('description', $types);
        $this->assertNotContains('brand', $types);
        $this->assertNotContains('generic', $types); // for empty_custom_field
    }

    public function testExtractTextAndImageChunksWithAlternativeImageTags()
    {
        $xmlString = <<<XML
<product>
    <id>4</id>
    <name>Product With Alt Images</name>
    <imageUrl>http://example.com/image1.jpg</imageUrl> <!-- generic -->
    <img_url>http://example.com/image2.png</img_url> <!-- generic -->
    <not_an_image_url>ただのテキスト</not_an_image_url>
</product>
XML;
        $productNode = new SimpleXMLElement($xmlString);
        $productId = "4";
        $productName = "Product With Alt Images";

        $chunks = $this->serializer->extractTextAndImageChunks($productNode, $productId, $productName);
        // id, name, imageUrl (as image_url), img_url (as image_url), not_an_image_url (as generic)
        $this->assertCount(5, $chunks);

        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'image_url', 'content' => 'http://example.com/image1.jpg', 'original_field' => 'imageUrl'
        ], $chunks);
        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'image_url', 'content' => 'http://example.com/image2.png', 'original_field' => 'img_url'
        ], $chunks);
        $this->assertContainsEquals([
            'product_id' => $productId, 'product_name' => $productName, 'type' => 'generic', 'content' => 'ただのテキスト', 'original_field' => 'not_an_image_url'
        ], $chunks);
    }
}
