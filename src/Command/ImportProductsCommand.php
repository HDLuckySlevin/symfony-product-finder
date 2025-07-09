<?php

namespace App\Command;

use App\Service\EmbeddingGeneratorInterface;
use App\Service\XmlImportService;
use App\Service\VectorStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-products',
    description: 'Import products from XML file, generate embeddings and sync with Milvus',
)]
class ImportProductsCommand extends Command
{
    private XmlImportService $xmlImportService;
    private EmbeddingGeneratorInterface $embeddingGenerator;
    private VectorStoreInterface $vectorStoreService;

    public function __construct(
        XmlImportService $xmlImportService,
        EmbeddingGeneratorInterface $embeddingGenerator,
        VectorStoreInterface $vectorStoreService
    ) {
        parent::__construct();
        $this->xmlImportService = $xmlImportService;
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorStoreService = $vectorStoreService;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('xml-file', InputArgument::REQUIRED, 'Path to XML file with products');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $xmlFile = $input->getArgument('xml-file');

        $io->title('Importing products from XML');

        try {
            // Import products from XML
            $io->section('Parsing XML file');
            $products = $this->xmlImportService->importFromFile($xmlFile);
            $io->success(sprintf('Successfully imported %d products from XML', count($products)));

            // Initialize Milvus collection
            $io->section('Initializing Milvus collection');
            $result = $this->vectorStoreService->initializeCollection();

            if ($result) {
                $io->success('Successfully initialized Milvus collection');
            } else {
                $io->error('Failed to initialize Milvus collection. Halting import.');
                return Command::FAILURE;
            }

            // Generate embeddings and insert chunks
            $io->section('Generating embeddings and inserting into Milvus');
            $progressBar = $io->createProgressBar(count($products));
            $progressBar->start();

            foreach ($products as $product) {
                $chunks = $this->embeddingGenerator->generateProductEmbeddings($product);
                if ($product->getId() !== null) {
                    $this->vectorStoreService->deleteProductVectors($product->getId());
                }
                $this->vectorStoreService->insertProductChunks($product, $chunks);
                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine(2);
            $io->success('Import process completed successfully');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('An error occurred during import: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
