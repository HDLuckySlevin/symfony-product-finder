<?php

namespace App\Tests\Command;

use App\Command\ImportProductsCommand;
use App\Serializer\ProductXmlSerializer;
use App\Service\EmbeddingGeneratorInterface;
use App\Service\VectorStoreInterface;
use App\Service\XmlImportService;
use App\Entity\Product; // May not be strictly needed if we mock ProductXmlSerializer well
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use SimpleXMLElement;

class ImportProductsCommandTest extends TestCase
{
    private XmlImportService $xmlImportServiceMock;
    private EmbeddingGeneratorInterface $embeddingGeneratorMock;
    private VectorStoreInterface $vectorStoreServiceMock;
    private ProductXmlSerializer $productXmlSerializerMock;

    protected function setUp(): void
    {
        $this->xmlImportServiceMock = $this->createMock(XmlImportService::class);
        $this->embeddingGeneratorMock = $this->createMock(EmbeddingGeneratorInterface::class);
        $this->vectorStoreServiceMock = $this->createMock(VectorStoreInterface::class);
        $this->productXmlSerializerMock = $this->createMock(ProductXmlSerializer::class);
    }

    public function testExecuteSuccess()
    {
        $application = new Application();
        $application->add(new ImportProductsCommand(
            $this->xmlImportServiceMock,
            $this->embeddingGeneratorMock,
            $this->vectorStoreServiceMock,
            $this->productXmlSerializerMock
        ));

        $command = $application->find('app:import-products');
        $commandTester = new CommandTester($command);

        // Create a dummy XML file
        $xmlFilePath = tempnam(sys_get_temp_dir(), 'test_products_') . '.xml';
        $xmlContent = <<<XML
<products>
    <product>
        <id>1</id>
        <name>Product Alpha</name>
        <description>Desc Alpha</description>
        <image_url>http://example.com/alpha.jpg</image_url>
    </product>
    <product>
        <id>2</id>
        <name>Product Beta</name>
        <specifications>
            <specification name="color">Blue</specification>
        </specifications>
    </product>
</products>
XML;
        file_put_contents($xmlFilePath, $xmlContent);

        // Mock XmlImportService: It returns an array of Product entities.
        // The command re-reads the XML for nodes, so this mock is mainly for the count.
        $product1Entity = new Product(); $product1Entity->setId(1); $product1Entity->setName('Product Alpha');
        $product2Entity = new Product(); $product2Entity->setId(2); $product2Entity->setName('Product Beta');
        $this->xmlImportServiceMock->method('importFromFile')
            ->with($xmlFilePath)
            ->willReturn([$product1Entity, $product2Entity]); // Mock return for initial parsing

        // Mock ProductXmlSerializer for the loop inside the command
        // For product 1
        $product1Node = new SimpleXMLElement(<<<XML
<product><id>1</id><name>Product Alpha</name><description>Desc Alpha</description><image_url>http://example.com/alpha.jpg</image_url></product>
XML
);
        $this->productXmlSerializerMock->method('deserialize')
            ->willReturnMap([
                [$this->isInstanceOf(SimpleXMLElement::class), $product1Entity], // Generalize if nodes differ significantly
            ]);

        $chunks1 = [
            ['product_id' => '1', 'product_name' => 'Product Alpha', 'type' => 'name', 'content' => 'Product Alpha'],
            ['product_id' => '1', 'product_name' => 'Product Alpha', 'type' => 'description', 'content' => 'Desc Alpha'],
            ['product_id' => '1', 'product_name' => 'Product Alpha', 'type' => 'image_url', 'content' => 'http://example.com/alpha.jpg'],
        ];
         $chunks2 = [
            ['product_id' => '2', 'product_name' => 'Product Beta', 'type' => 'name', 'content' => 'Product Beta'],
            ['product_id' => '2', 'product_name' => 'Product Beta', 'type' => 'specification', 'content' => 'color: Blue'],
        ];

        // This is tricky because extractTextAndImageChunks is called in a loop.
        // We need to match the SimpleXMLElement argument or use willReturnOnConsecutiveCalls.
        // For simplicity, let's assume it's called twice and returns these chunk sets.
         $this->productXmlSerializerMock->method('extractTextAndImageChunks')
            ->willReturnOnConsecutiveCalls($chunks1, $chunks2);


        // Mock EmbeddingGeneratorInterface
        $this->embeddingGeneratorMock->method('generateTextEmbedding')
            ->willReturnCallback(function(string $text) {
                return [crc32($text) / 10000000000]; // Dummy embedding based on text
            });
        $this->embeddingGeneratorMock->method('generateImageEmbedding')
            ->willReturnCallback(function(string $url) {
                return [crc32($url) / 10000000000 + 0.5]; // Dummy different embedding for image
            });

        // Mock VectorStoreInterface
        $this->vectorStoreServiceMock->method('initializeCollection')->willReturn(true);
        $this->vectorStoreServiceMock->method('insertEmbeddings')
            ->with($this->callback(function ($allEmbeddings) {
                // Check if we have 3 text embeddings + 1 image embedding + 1 spec = 5 total
                $this->assertCount(5, $allEmbeddings);
                $this->assertEquals('1', $allEmbeddings[0]['product_id']);
                $this->assertEquals('name', $allEmbeddings[0]['type']);
                $this->assertEquals('Product Alpha', $allEmbeddings[0]['product_name']);
                // ... more assertions on the structure of $allEmbeddings
                return true;
            }))
            ->willReturn(true);

        $commandTester->execute(['xml-file' => $xmlFilePath]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Successfully imported 2 products from XML', $output);
        $this->assertStringContainsString('Generated a total of 5 embeddings for all products.', $output);
        $this->assertStringContainsString('Successfully initialized Milvus collection', $output);
        $this->assertStringContainsString('Successfully inserted 5 embeddings into Milvus.', $output);
        $this->assertStringContainsString('Import process completed successfully', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());

        unlink($xmlFilePath);
    }
}
