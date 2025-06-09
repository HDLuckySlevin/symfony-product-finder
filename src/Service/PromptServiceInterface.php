<?php

namespace App\Service;

/**
 * Interface for services that provide access to prompts
 */
interface PromptServiceInterface
{
    /****
 * Retrieves a prompt string identified by section and key, substituting placeholders with provided parameters.
 *
 * @param string $section Section name where the prompt is defined.
 * @param string $key Key identifying the specific prompt.
 * @param array<string, string> $parameters Optional associative array of placeholder replacements.
 * @return string The prompt string with parameters substituted.
 * @throws \InvalidArgumentException If the specified prompt key does not exist.
 */
    public function getPrompt(string $section, string $key, array $parameters = []): string;
}
