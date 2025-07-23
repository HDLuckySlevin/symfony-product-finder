<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Simple service to store and retrieve search queries in the user's session.
 */
class SearchHistoryService
{
    private SessionInterface $session;
    private int $maxEntries;

    public function __construct(SessionInterface $session, int $maxEntries = 5)
    {
        $this->session = $session;
        $this->maxEntries = $maxEntries;
    }

    /**
     * Add a query to the history.
     */
    public function addQuery(string $query): void
    {
        $history = $this->session->get('search_history', []);
        $history[] = $query;
        if (count($history) > $this->maxEntries) {
            $history = array_slice($history, -$this->maxEntries);
        }
        $this->session->set('search_history', $history);
    }

    /**
     * Retrieve all stored queries.
     *
     * @return string[]
     */
    public function getHistory(): array
    {
        return $this->session->get('search_history', []);
    }

    /**
     * Remove all stored queries.
     */
    public function clear(): void
    {
        $this->session->remove('search_history');
    }
}

