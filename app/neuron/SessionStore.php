<?php

declare(strict_types=1);

namespace app\neuron;

use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Workflow\Persistence\FilePersistence;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_filter;
use function array_map;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function pathinfo;
use function time;
use function trim;
use function uksort;
use function uniqid;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PATHINFO_FILENAME;
use const SORT_DESC;

class SessionStore
{
    public function create(): array
    {
        $id = uniqid('sess_');
        $meta = [
            'id' => $id,
            'title' => 'New Session',
            'created_at' => time(),
            'updated_at' => time(),
            'pending_interrupt' => null,
        ];

        $this->ensureSessionDirectory($id);
        $this->writeMeta($id, $meta);

        return $meta;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $pattern = $this->sessionsRoot() . '/*/meta.json';
        $files = glob($pattern) ?: [];
        $sessions = [];

        foreach ($files as $file) {
            $meta = $this->readMetaByFile($file);
            if ($meta !== null) {
                $sessions[] = $meta;
            }
        }

        usort($sessions, static fn (array $a, array $b): int => ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0));

        return $sessions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $sessionId): ?array
    {
        $file = $this->metaPath($sessionId);
        if (!is_file($file)) {
            return null;
        }

        return $this->readMetaByFile($file);
    }

    public function delete(string $sessionId): void
    {
        $directory = $this->sessionDirectory($sessionId);
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }

    public function touch(string $sessionId): void
    {
        $meta = $this->require($sessionId);
        $meta['updated_at'] = time();
        $this->writeMeta($sessionId, $meta);
    }

    public function updateTitleIfNeeded(string $sessionId, string $candidate): void
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return;
        }

        $meta = $this->require($sessionId);
        if (($meta['title'] ?? '') !== 'New Session') {
            return;
        }

        $meta['title'] = mb_substr($candidate, 0, 40);
        $meta['updated_at'] = time();
        $this->writeMeta($sessionId, $meta);
    }

    public function setPendingInterrupt(string $sessionId, ?array $interrupt): void
    {
        $meta = $this->require($sessionId);
        $meta['pending_interrupt'] = $interrupt;
        $meta['updated_at'] = time();
        $this->writeMeta($sessionId, $meta);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPendingInterrupt(string $sessionId): ?array
    {
        $meta = $this->require($sessionId);
        return $meta['pending_interrupt'] ?? null;
    }

    public function history(string $sessionId): FileChatHistory
    {
        return new FileChatHistory($this->sessionDirectory($sessionId), 'history', 50000, '', '.chat');
    }

    public function workflowPersistence(string $sessionId): FilePersistence
    {
        $directory = $this->workflowDirectory($sessionId);
        $this->ensureDirectory($directory);

        return new FilePersistence($directory, '', '.store');
    }

    public function workflowToken(string $sessionId): string
    {
        return 'session_workflow';
    }

    public function sessionDirectory(string $sessionId): string
    {
        return $this->sessionsRoot() . '/' . $sessionId;
    }

    public function docsDirectory(string $sessionId): string
    {
        $directory = $this->sessionDirectory($sessionId) . '/docs';
        $this->ensureDirectory($directory);

        return $directory;
    }

    private function workflowDirectory(string $sessionId): string
    {
        return $this->sessionDirectory($sessionId) . '/workflow';
    }

    private function ensureSessionDirectory(string $sessionId): void
    {
        $this->ensureDirectory($this->sessionDirectory($sessionId));
        $this->ensureDirectory($this->docsDirectory($sessionId));
        $this->ensureDirectory($this->workflowDirectory($sessionId));
    }

    private function metaPath(string $sessionId): string
    {
        return $this->sessionDirectory($sessionId) . '/meta.json';
    }

    private function writeMeta(string $sessionId, array $meta): void
    {
        $this->ensureSessionDirectory($sessionId);
        file_put_contents(
            $this->metaPath($sessionId),
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readMetaByFile(string $file): ?array
    {
        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $meta = json_decode($raw, true);
        return is_array($meta) ? $meta : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function require(string $sessionId): array
    {
        $meta = $this->get($sessionId);
        if ($meta === null) {
            throw new \RuntimeException('Session not found.');
        }

        return $meta;
    }

    private function sessionsRoot(): string
    {
        $directory = runtime_path('sessions');
        $this->ensureDirectory($directory);

        return $directory;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        mkdir($directory, 0775, true);
    }
}
