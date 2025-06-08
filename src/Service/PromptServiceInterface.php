<?php

namespace App\Service;

/**
 * Interface for services that provide access to prompts
 */
interface PromptServiceInterface
{
    /**
     * Get a prompt by its key
     * 
     * @param string $section The section in the YAML file
     * @param string $key The key of the prompt
     * @param array $parameters Parameters to replace in the prompt
     * @return string The prompt with parameters replaced
     * @throws \InvalidArgumentException If the prompt key is not found
     */
    public function getPrompt(string $section, string $key, array $parameters = []): string;
}