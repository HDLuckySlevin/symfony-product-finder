<?php

namespace App\Command;

use App\Entity\Product;
use App\Service\EmbeddingGeneratorInterface;
use App\Service\XmlImportService;
use App\Service\ZillizVectorDBService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-products',
    description: 'Import products from XML file, generate embeddings and sync with Zilliz',
)]
class ImportProductsCommand extends Command
{
    private XmlImportService $xmlImportService;
    private EmbeddingGeneratorInterface $embeddingGenerator;
    private ZillizVectorDBService $vectorDBService;

    public function __construct(
        XmlImportService $xmlImportService,
        EmbeddingGeneratorInterface $embeddingGenerator,
        ZillizVectorDBService $vectorDBService
    ) {
        parent::__construct();
        $this->xmlImportService = $xmlImportService;
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorDBService = $vectorDBService;
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

            // Generate embeddings
            $io->section('Generating embeddings');
            $productsWithEmbeddings = [];
            $progressBar = $io->createProgressBar(count($products));
            $progressBar->start();

            foreach ($products as $product) {
                if (!$product instanceof Product) {
                    continue;
                }

                // Generate product embedding (includes specification, description, and features in one chunk)
                $embedding = $this->embeddingGenerator->generateEmbedding($product);
                $product->setEmbeddings($embedding);
                $productsWithEmbeddings[] = $product;

                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine(2);
            $io->success(sprintf('Generated embeddings for %d products (including specification, description, and features in one chunk)', count($productsWithEmbeddings)));

            // Initialize Zilliz collection
            $io->section('Initializing Zilliz collection');
            $result = $this->vectorDBService->initializeCollection();

            if ($result) {
                $io->success('Successfully initialized Zilliz collection');
            } else {
                $io->warning('Failed to initialize Zilliz collection. Using mock mode.');
            }

            // Insert products into Zilliz
            $io->section('Inserting products into Zilliz');
            $result = $this->vectorDBService->insertProducts($productsWithEmbeddings);

            if ($result) {
                $io->success('Successfully inserted products into Zilliz');
            } else {
                $io->warning('Failed to insert products into Zilliz. Using mock mode.');
            }

            // No longer inserting separate features and specifications
            // All product data (including specification, description, and features) is now in a single chunk


            $io->success('Import process completed successfully');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('An error occurred during import: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
