<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\JsonResponse;

class ApiKeySubscriber implements EventSubscriberInterface
{
    private string $apiKey;
    private string $cookieName;
    /**
     * @var array<string>
     */
    private array $excludedPaths;

    /**
     * @param array<string> $excludedPaths
     */
    public function __construct(string $apiKey, string $cookieName = 'api_key', array $excludedPaths = ['/'])
    {
        $this->apiKey = $apiKey;
        $this->cookieName = $cookieName;
        $this->excludedPaths = $excludedPaths;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 255],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (in_array($request->getPathInfo(), $this->excludedPaths, true)) {
            return;
        }

        $providedKey = $request->headers->get('X-API-Key');
        if ($providedKey === null) {
            $providedKey = $request->cookies->get($this->cookieName);
        }

        if ($providedKey !== $this->apiKey) {
            $event->setResponse(new JsonResponse(['message' => 'Invalid API key'], 401));
        }
    }
}
