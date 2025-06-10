<?php

namespace App\Tests\Service;

use App\Service\OpenAIVisionService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ServiceAvailabilityTest extends KernelTestCase
{
    public function testOpenAIVisionServiceIsAvailable(): void
    {
        self::bootKernel();

        // Get the service container
        $container = self::getContainer();

        // Fetch the OpenAIVisionService
        // Use the FQCN as the service ID, which is standard with autoconfigure/autowire
        $visionService = $container->get(OpenAIVisionService::class);

        $this->assertInstanceOf(OpenAIVisionService::class, $visionService);
    }

    public function testOpenAIClientServiceIsAvailable(): void
    {
        // This test is implicitly covered by the one above,
        // as OpenAIVisionService depends on OpenAI\Client.
        // However, we can also explicitly test for OpenAI\Client if desired,
        // though it might require setting a dummy OPENAI_API_KEY for the test environment
        // if the factory attempts to use it immediately and it's not set.

        self::bootKernel();
        $container = self::getContainer();

        // It's good to ensure OPENAI_API_KEY is set for this test,
        // even if it's a dummy value, to avoid issues if the factory tries to use it.
        // This can be handled in phpunit.xml.dist for the test environment.
        // For now, we assume it might be null or a dummy value and the factory handles it gracefully
        // or the test environment has it set.

        // Attempt to fetch the OpenAI client
        // Note: The service ID for OpenAI\Client was explicitly defined in services.yaml
        $openAiClient = $container->get(\OpenAI\Client::class);
        // Using \OpenAI\Client::class assumes 'OpenAI\Client' is the correct FQCN for the service ID.
        // If an alias or different ID was used in services.yaml (like 'openai.client'), use that string instead.
        // Based on our services.yaml, 'OpenAI\Client' is the ID.

        $this->assertInstanceOf(\OpenAI\Client::class, $openAiClient);
    }
}
