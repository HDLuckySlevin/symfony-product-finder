<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Command\Command;
use App\Command\TestSearchCommand;
use App\Command\ProcessImageCommand;
use Symfony\Component\Routing\Annotation\Route;

class WebInterfaceController extends AbstractController
{
    private const MAX_QUERY_LENGTH = 500;
    private const MAX_IMAGE_SIZE = 5242880; // 5 MB
    private const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png'];
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
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

        $application = new Application();
        $application->setAutoExit(false);
        $application->add($testSearchCommand);
        $processImageCommand->setApplication($application);

        $input = new ArrayInput([
            'command' => $processImageCommand->getName(),
            'image' => $file->getPathname(),
        ]);

        $output = new BufferedOutput();
        $result = $processImageCommand->run($input, $output);

        return new JsonResponse([
            'success' => $result === Command::SUCCESS,
            'output' => $output->fetch(),
        ]);
    }

}
