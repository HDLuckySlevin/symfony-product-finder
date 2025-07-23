<?php

namespace App\Tests;

use App\Service\SearchHistoryService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class SearchHistoryServiceTest extends TestCase
{
    public function testHistoryIsLimitedAndStored(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $history = [];
        $session->method('get')->willReturnCallback(fn($key, $default) => $history);
        $session->expects($this->exactly(5))->method('set')->willReturnCallback(function($key, $val) use (&$history) { $history = $val; });
        $service = new SearchHistoryService($session, 3);
        $service->addQuery('a');
        $service->addQuery('b');
        $service->addQuery('c');
        $service->addQuery('d');
        $this->assertCount(3, $service->getHistory());
    }
}

