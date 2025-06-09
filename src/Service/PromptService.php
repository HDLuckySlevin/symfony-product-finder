<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Service for loading and managing prompts from YAML configuration
 */
class PromptService implements PromptServiceInterface
{
    private array $prompts;

    /**
     * Constructor
     * 
     * @param ParameterBagInterface $parameterBag The parameter bag service
     */
    public function __construct(
        private ParameterBagInterface $parameterBag
    ) {
        $this->loadPrompts();
    }

    /**
     * Load prompts from the YAML configuration file
     */
    private function loadPrompts(): void
    {
        $projectDir = $this->parameterBag->get('kernel.project_dir');
        $promptsFile = $projectDir . '/config/prompts.yaml';

        if (!file_exists($promptsFile)) {
            throw new \RuntimeException('Prompts configuration file not found: ' . $promptsFile);
        }

        $this->prompts = Yaml::parseFile($promptsFile);
    }

    /**
     * Get a prompt by its key
     * 
     * @param string $section The section in the YAML file
     * @param string $key The key of the prompt
     * @param array $parameters Parameters to replace in the prompt
     * @return string The prompt with parameters replaced
     * @throws \InvalidArgumentException If the prompt key is not found
     */
    public function getPrompt(string $section, string $key, array $parameters = []): string
    {
        if (!isset($this->prompts[$section]) || !isset($this->prompts[$section][$key])) {
            throw new \InvalidArgumentException("Prompt not found: $section.$key");
        }

        $prompt = $this->prompts[$section][$key];

        // Replace parameters in the prompt
        foreach ($parameters as $param => $value) {
            $prompt = str_replace("%$param%", $value, $prompt);
        }

        return $prompt;
    }
}
