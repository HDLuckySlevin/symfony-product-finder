<?php

namespace App\Tests;

use App\Command\ImportProductsCommand;
use App\Entity\Product;
use App\Service\EmbeddingGeneratorInterface;
use App\Service\VectorStoreInterface;
use App\Service\XmlImportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ImportProductsCommandTest extends TestCase
{
    public function testExistingVectorsAreRemovedBeforeInsert(): void
    {
        $product = new Product();
        $product->setId(1);
        $product->setName('Test');

        $xmlService = $this->createMock(XmlImportService::class);
        $xmlService->method('importFromFile')->willReturn([$product]);

        $embeddingGenerator = $this->createMock(EmbeddingGeneratorInterface::class);
        $embeddingGenerator->expects($this->once())
            ->method('generateProductEmbeddings')
            ->with($product)
            ->willReturn([[ 'vector' => [0.1], 'type' => 'product' ]]);

        $vectorStore = $this->createMock(VectorStoreInterface::class);
        $vectorStore->expects($this->once())
            ->method('initializeCollection')
            ->willReturn(true);
        $vectorStore->expects($this->once())
            ->method('deleteProductVectors')
            ->with(1)
            ->willReturn(true);
        $vectorStore->expects($this->once())
            ->method('insertProductChunks')
            ->with($product, [[ 'vector' => [0.1], 'type' => 'product' ]])
            ->willReturn(true);

        $command = new ImportProductsCommand($xmlService, $embeddingGenerator, $vectorStore);
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $file = tempnam(sys_get_temp_dir(), 'xml');
        file_put_contents($file, '<products></products>');
        $tester->execute(['xml-file' => $file]);

        $this->assertEquals(Command::SUCCESS, $tester->getStatusCode());
        unlink($file);
    }
}
