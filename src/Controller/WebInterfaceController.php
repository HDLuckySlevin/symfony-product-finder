<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Command\Command;
use App\Command\TestSearchCommand;
use App\Command\ProcessImageCommand;
use App\Command\ProcessAudioCommand;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

class WebInterfaceController extends AbstractController
{
    private const MAX_QUERY_LENGTH = 500;
    private const MAX_IMAGE_SIZE = 5242880; // 5 MB
    private const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png'];
    private const MAX_AUDIO_SIZE = 5242880; // 5 MB

    private LoggerInterface $logger;
    private string $apiKey;

    public function __construct(LoggerInterface $logger, string $apiKey)
    {
        $this->logger = $logger;
        $this->apiKey = $apiKey;
    }
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        $response = $this->render('home/index.html.twig');
        $cookie = Cookie::create('api_key', $this->apiKey)
            ->withHttpOnly(true);
        $response->headers->setCookie($cookie);
        return $response;
    }

    #[Route('/search', name: 'app_web_search', methods: ['POST'])]
    public function search(Request $request, TestSearchCommand $command, KernelInterface $kernel): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $query = isset($data['query']) ? trim((string) $data['query']) : '';
        if ($query === '' || mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return new JsonResponse(
                ['success' => false, 'message' => 'Invalid query'],
                400
            );
        }


        $application = new Application();
        $application->setAutoExit(false);
        $command->setApplication($application);

        $input = new ArrayInput([
            'command' => $command->getName(),
            'query' => $query,
            '--simple' => true,
        ]);

        $output = new BufferedOutput();
        $result = $command->run($input, $output);

        return new JsonResponse([
            'success' => $result === Command::SUCCESS,
            'output' => $output->fetch(),
        ]);
    }

    #[Route('/search/image', name: 'app_web_search_image', methods: ['POST'])]
    public function searchImage(Request $request, ProcessImageCommand $processImageCommand, TestSearchCommand $testSearchCommand, KernelInterface $kernel): JsonResponse
    {
        $file = $request->files->get('image');
        if (!$file) {
            return new JsonResponse(['success' => false, 'message' => 'No image uploaded'], 400);
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_IMAGE_TYPES, true)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid image type'], 400);
        }

        if ($file->getSize() > self::MAX_IMAGE_SIZE) {
            return new JsonResponse(['success' => false, 'message' => 'Image too large'], 400);
        }

        $application = new Application();
        $application->setAutoExit(false);
        $application->add($testSearchCommand);
        $processImageCommand->setApplication($application);

        $imagePath = $file->getPathname();

        $input = new ArrayInput([
            'command' => $processImageCommand->getName(),
            'image' => $imagePath,
            '--simple' => true,
        ]);

        $output = new BufferedOutput();
        $result = $processImageCommand->run($input, $output);

        if (is_string($imagePath) && file_exists($imagePath)) {
            @unlink($imagePath);
        }

        return new JsonResponse([
            'success' => $result === Command::SUCCESS,
            'output' => $output->fetch(),
        ]);
    }

    #[Route('/search/audio', name: 'app_web_search_audio', methods: ['POST'])]
    public function searchAudio(Request $request, ProcessAudioCommand $processAudioCommand, TestSearchCommand $testSearchCommand, KernelInterface $kernel): JsonResponse
    {
        $file = $request->files->get('audio');
        if (!$file) {
            $this->logger->error('No audio uploaded');
            return new JsonResponse(['success' => false, 'message' => 'No audio uploaded'], 400);
        }

        $mimeType = $file->getMimeType() ?? '';
        if (!str_starts_with($mimeType, 'audio/') && $mimeType !== 'video/webm') {
            $this->logger->error('Invalid audio type', ['mime' => $mimeType]);
            return new JsonResponse(['success' => false, 'message' => 'Invalid audio type'], 400);
        }

        if ($file->getSize() > self::MAX_AUDIO_SIZE) {
            $this->logger->error('Uploaded audio too large', ['size' => $file->getSize()]);
            return new JsonResponse(['success' => false, 'message' => 'Audio too large'], 400);
        }

        $this->logger->info('Received audio upload', [
            'mime' => $mimeType,
            'size' => $file->getSize(),
        ]);

        $extension = $file->guessExtension() ?: 'webm';
        $filename = uniqid('audio_', true) . '.' . $extension;
        $tempDir = sys_get_temp_dir();
        $file->move($tempDir, $filename);
        $audioPath = $tempDir . '/' . $filename;

        $this->logger->debug('Stored uploaded audio', ['path' => $audioPath]);

        $application = new Application();
        $application->setAutoExit(false);
        $application->add($testSearchCommand);
        $processAudioCommand->setApplication($application);

        $input = new ArrayInput([
            'command' => $processAudioCommand->getName(),
            'audio' => $audioPath,
            '--simple' => true,
        ]);

        $output = new BufferedOutput();
        $result = $processAudioCommand->run($input, $output);
        $commandOutput = $output->fetch();

        $this->logger->info('Finished processing audio', [
            'exit_code' => $result,
            'output' => $commandOutput,
        ]);

        if (is_string($audioPath) && file_exists($audioPath)) {
            @unlink($audioPath);
        }

        return new JsonResponse([
            'success' => $result === Command::SUCCESS,
            'output' => $commandOutput,
        ]);
    }

}

