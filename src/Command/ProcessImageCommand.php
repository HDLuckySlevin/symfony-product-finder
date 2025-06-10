<?php

namespace App\Command;

use App\Service\OpenAIVisionService;
use App\Service\EmbeddingGeneratorInterface; // Added
use App\Service\ZillizVectorDBService; // Added
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle; // For better looking output, optional

/**
 * Symfony console command to process an image using the OpenAI GPT-4o vision model.
 *
 * This command takes an image path and a text prompt, sends them to the
 * OpenAIVisionService to get a textual description. This description is then
 * converted into a vector embedding and used to query a Zilliz/Milvus vector
 * database to find and display the top 5 most similar products.
 */
class ProcessImageCommand extends Command
{
    protected static $defaultName = 'app:process-image';

    /**
     * @var OpenAIVisionService The service responsible for OpenAI API interactions.
     */
    private OpenAIVisionService $visionService;

    /**
     * @var EmbeddingGeneratorInterface The service to generate text embeddings.
     */
    private EmbeddingGeneratorInterface $embeddingGenerator;

    /**
     * @var ZillizVectorDBService The service to interact with the vector database.
     */
    private ZillizVectorDBService $vectorDBService;

    /**
     * ProcessImageCommand constructor.
     *
     * @param OpenAIVisionService $visionService The service to interact with OpenAI's vision capabilities.
     * @param EmbeddingGeneratorInterface $embeddingGenerator The service to generate text embeddings.
     * @param ZillizVectorDBService $vectorDBService The service to interact with the vector database.
     */
    public function __construct(
        OpenAIVisionService $visionService,
        EmbeddingGeneratorInterface $embeddingGenerator,
        ZillizVectorDBService $vectorDBService
    ) {
        parent::__construct();
        $this->visionService = $visionService;
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorDBService = $vectorDBService;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Processes an image with GPT-4o, then finds similar products in the vector database.')
            ->addArgument('image_path', InputArgument::REQUIRED, 'The path to the image file.')
            ->addArgument('preprompt', InputArgument::REQUIRED, 'The prompt to send with the image.');
    }

    /**
     * Executes the command to process the image, generate a description,
     * and find similar products.
     *
     * Retrieves image path and prompt from arguments. First, it calls OpenAIVisionService
     * to get an image description. Then, it generates a vector embedding for this
     * description using EmbeddingGeneratorInterface. Finally, it queries
     * ZillizVectorDBService to find the top 5 similar products and outputs
     * both the image description and the list of similar products or relevant error messages.
     *
     * @param InputInterface $input The console input.
     * @param OutputInterface $output The console output.
     * @return int Command::SUCCESS on success, Command::FAILURE on error.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); // Optional: for styled output

        $imagePath = $input->getArgument('image_path');
        $preprompt = $input->getArgument('preprompt');

        $io->title('Image Processing Command');
        $io->writeln('Image path: ' . $imagePath);
        $io->writeln('Prompt: ' . $preprompt);
        $io->newLine();

        if (!file_exists($imagePath)) {
            $io->error("Error: Image file not found at path: " . $imagePath);
            return Command::FAILURE;
        }

        if (@getimagesize($imagePath) === false) {
            $io->error("Error: The file at path is not a valid image or is corrupted: " . $imagePath);
            return Command::FAILURE;
        }

        try {
            $io->section('Attempting to get description from OpenAI Vision API...');
            $imageGptResult = $this->visionService->getDescriptionForImage($imagePath, $preprompt);

            $io->success('Successfully retrieved description:');
            $io->block($imageGptResult);

            // Generate Embedding
            $io->section('Generating embedding for description...');
            try {
                $embedding = $this->embeddingGenerator->generateQueryEmbedding($imageGptResult);
                $io->writeln('Embedding generated successfully.');
            } catch (\Exception $e) {
                $io->error('Failed to generate embedding for the description: ' . $e->getMessage());
                return Command::FAILURE;
            }

            // Search Similar Products
            $io->section('Searching for similar products in vector database...');
            try {
                // Assuming ZillizVectorDBService::searchSimilarProducts returns an array of objects
                // each with getId(), getName(), and getScore() methods.
                $similarProducts = $this->vectorDBService->searchSimilarProducts($embedding, 5);
            } catch (\Exception $e) {
                $io->error('Failed to search for similar products: ' . $e->getMessage());
                return Command::FAILURE;
            }

            // Format and Display Results
            $io->section('Top 5 Similar Products:');
            if (empty($similarProducts)) {
                $io->writeln('No similar products found.');
            } else {
                $tableRows = [];
                foreach ($similarProducts as $index => $product) {
                    // $product is now expected to be an associative array
                    $score = 'N/A';
                    if (isset($product['distance'])) {
                        $score = is_float($product['distance']) ? number_format($product['distance'], 4) : $product['distance'];
                    } elseif (isset($product['score'])) { // Fallback if 'score' is used
                        $score = is_float($product['score']) ? number_format($product['score'], 4) : $product['score'];
                    }

                    $tableRows[] = [
                        $index + 1,
                        $product['primary_key'] ?? 'N/A',
                        $product['title'] ?? 'N/A',
                        $score
                    ];
                }
                $io->table(['#', 'ID', 'Product Name', 'Similarity Score'], $tableRows);
            }

            return Command::SUCCESS;

        } catch (\InvalidArgumentException $e) { // From initial validation or vision service
            $io->error('Invalid argument or setup: ' . $e->getMessage());
            return Command::FAILURE;
        } catch (\RuntimeException $e) { // From vision service or other runtime issues
            $io->error('Runtime error during processing: ' . $e->getMessage());
            return Command::FAILURE;
        } catch (\Exception $e) { // Generic catch-all for unexpected errors
            $io->error('An unexpected error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
