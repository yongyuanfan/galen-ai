<?php

declare(strict_types=1);

namespace app\neuron\service;

use app\neuron\generator\SessionTitleGenerator;
use app\neuron\store\SessionStore;

class SessionTitleService
{
    public function __construct(
        private SessionStore $store,
        private SessionTitleGenerator $generator,
    ) {
    }
    
    /**
     * @return array<string, mixed>
     */
    public function generateNowIfNeeded(string $sessionId, string $message): array
    {
        if ($this->store->fallbackTitle($message) === $this->store->defaultTitle()) {
            return $this->store->requireSession($sessionId);
        }

        $current = $this->store->requireSession($sessionId);
        if (($current['title'] ?? '') !== $this->store->defaultTitle()) {
            return $current;
        }

        if (!$this->store->markTitleGenerationPending($sessionId)) {
            return $this->store->requireSession($sessionId);
        }

        try {
            $title = $this->generator->generate($message);
        } catch (\Throwable) {
            $title = $this->store->fallbackTitle($message);
        }

        $this->store->completeTitleGeneration($sessionId, $title);

        return $this->store->requireSession($sessionId);
    }
}
