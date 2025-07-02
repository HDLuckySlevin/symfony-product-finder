<?php

namespace App\Command;

use App\Service\EmbeddingGeneratorInterface;
use App\Service\PromptServiceInterface;
use App\Service\SearchServiceInterface;
use App\Service\VectorStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-search',
    description: 'Test the product search functionality with a natural language query',
)]
class TestSearchCommand extends Command
{
    private EmbeddingGeneratorInterface $embeddingGenerator;
    private VectorStoreInterface $vectorStoreService;
    private SearchServiceInterface $searchService;
    private PromptServiceInterface $promptService;

    public function __construct(
        EmbeddingGeneratorInterface $embeddingGenerator,
        VectorStoreInterface $vectorStoreService,
        SearchServiceInterface $searchService,
        PromptServiceInterface $promptService
    ) {
        parent::__construct();
        $this->embeddingGenerator = $embeddingGenerator;
        $this->vectorStoreService = $vectorStoreService;
        $this->searchService = $searchService;
        $this->promptService = $promptService;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Natural language search query')
            ->addOption('simple', null, null, 'Reduce output to the similarity table and recommendation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $query = $input->getArgument('query');
        $simple = (bool) $input->getOption('simple');

        if (!$simple) {
            $io->title('Testing product search');
            $io->section('Search query: ' . $query);
        }

        try {
            // Generate embedding for the query
            if (!$simple) {
                $io->text('Generating embedding for the query...');
            }
            $queryEmbedding = $this->embeddingGenerator->generateQueryEmbedding($query);
            if (!$simple) {
                $io->success('Successfully generated embedding for the query');
            }

            // Search for similar products
            if (!$simple) {
                $io->text('Searching for similar products...');
            }
            $results = $this->vectorStoreService->searchSimilarProducts($queryEmbedding, 3);

            if (empty($results)) {
                $io->warning('No products found matching the query');
                return Command::SUCCESS;
            }

            // Filter results to only include products with distance <= 0.5
            $filteredResults = array_filter($results, function($result) {
                return isset($result['distance']) && $result['distance'] >= 0.5;
            });

            if (empty($filteredResults)) {
                $io->warning('No products found with similarity <= 0.5');
                return Command::SUCCESS;
            }

            if (!$simple) {
                $io->success(sprintf('Found %d products matching the query with similarity <= 0.5', count($filteredResults)));
            }

            // Process results using search service
            if (!$simple) {
                $io->text('Processing results with OpenAI...');
            }

            // Create system prompt that acts as a product finder
            $systemPromptContent = $this->promptService->getPrompt('product_finder', 'system_prompt');
            $systemPrompt = [
                'role' => 'system',
                'content' => $systemPromptContent
            ];

            // Create user message with query and products
            $productsList = '';
            foreach ($filteredResults as $index => $result) {
                $productsList .= ($index + 1) . ". " . ($result['title'] ?? 'Unknown product') . " (Similarity: " . (($result['distance'] ?? 0)) . ")\n";
            }

            $userMessageContent = $this->promptService->getPrompt('product_finder', 'user_message_template', [
                'query' => $query,
                'products_list' => $productsList
            ]);

            $userMessage = [
                'role' => 'user',
                'content' => $userMessageContent
            ];

            $messages = [$systemPrompt, $userMessage];
            $recommendation = $this->searchService->generateChatCompletion($messages);

            // Display raw results
            if (!$simple) {
                $io->section('Raw search results (similarity <= 0.5):');
            }
            $table = [];
            foreach ($filteredResults as $index => $result) {
                $table[] = [
                    $index + 1,
                    $result['id'] ?? 'N/A',
                    $result['title'] ?? 'Unknown product',
                    $result['distance'] ?? 'N/A',
                ];
            }

            $io->table(['#', 'ID', 'Product Name', 'Similarity'], $table);

            // Display OpenAI recommendation
            if (!$simple) {
                $io->section('OpenAI Recommendation:');
            }
            $io->writeln($recommendation);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('An error occurred during search: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
