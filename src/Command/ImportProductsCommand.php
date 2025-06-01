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
            $featureEmbeddings = [];
            $specificationEmbeddings = [];
            $progressBar = $io->createProgressBar(count($products));
            $progressBar->start();

            foreach ($products as $product) {
                if (!$product instanceof Product) {
                    continue;
                }

                // Generate product embedding
                $embedding = $this->embeddingGenerator->generateEmbedding($product);
                $product->setEmbeddings($embedding);
                $productsWithEmbeddings[] = $product;

                // Generate feature embeddings
                $featureEmbeddings[$product->getId()] = $this->embeddingGenerator->generateFeatureEmbeddings($product);

                // Generate specification embeddings
                $specificationEmbeddings[$product->getId()] = $this->embeddingGenerator->generateSpecificationEmbeddings($product);

                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine(2);
            $io->success(sprintf('Generated embeddings for %d products and their features/specifications', count($productsWithEmbeddings)));

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

            // Insert product features into Zilliz
            $io->section('Inserting product features into Zilliz');
            $featuresSuccess = true;
            foreach ($productsWithEmbeddings as $product) {
                $productId = $product->getId();
                if (!isset($featureEmbeddings[$productId]) || empty($featureEmbeddings[$productId])) {
                    continue;
                }

                $result = $this->vectorDBService->insertProductFeatures($product, $featureEmbeddings[$productId]);
                if (!$result) {
                    $featuresSuccess = false;
                }
            }

            if ($featuresSuccess) {
                $io->success('Successfully inserted product features into Zilliz');
            } else {
                $io->warning('Failed to insert some product features into Zilliz.');
            }

            // Insert product specifications into Zilliz
            $io->section('Inserting product specifications into Zilliz');
            $specificationsSuccess = true;
            foreach ($productsWithEmbeddings as $product) {
                $productId = $product->getId();
                if (!isset($specificationEmbeddings[$productId]) || empty($specificationEmbeddings[$productId])) {
                    continue;
                }

                $result = $this->vectorDBService->insertProductSpecifications($product, $specificationEmbeddings[$productId]);
                if (!$result) {
                    $specificationsSuccess = false;
                }
            }

            if ($specificationsSuccess) {
                $io->success('Successfully inserted product specifications into Zilliz');
            } else {
                $io->warning('Failed to insert some product specifications into Zilliz.');
            }


            $io->success('Import process completed successfully');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('An error occurred during import: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
