<?php

declare(strict_types=1);

namespace app\neuron\store;

use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Workflow\Persistence\FilePersistence;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function time;
use function trim;
use function unlink;

use const LOCK_EX;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @phpstan-type PendingInterrupt array<string, mixed>
 * @phpstan-type SessionMeta array{
 *     id: string,
 *     title: string,
 *     created_at: int,
 *     updated_at: int,
 *     title_generation_pending: bool,
 *     pending_interrupt: PendingInterrupt|null
 * }
 */
class SessionStore
{
    private const DEFAULT_TITLE = 'New Session';
    private const TITLE_LIMIT = 40;
    private const HISTORY_LIMIT = 50000;
    private const WORKFLOW_TOKEN = 'session_workflow';

    public function create(): array
    {
        $id = 'sess_' . bin2hex(random_bytes(16));
        $now = time();
        $meta = [
            'id' => $id,
            'title' => self::DEFAULT_TITLE,
            'created_at' => $now,
            'updated_at' => $now,
            'title_generation_pending' => false,
            'pending_interrupt' => null,
        ];

        $this->ensureSessionDirectory($id);
        $this->writeMeta($id, $meta);

        return $meta;
    }

    /**
     * @return array<int, SessionMeta>
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
     * @return SessionMeta|null
     */
    public function get(string $sessionId): ?array
    {
        $file = $this->metaPath($sessionId);
        if (!is_file($file)) {
            return null;
        }

        return $this->readMetaByFile($file);
    }

    /**
     * @return SessionMeta
     */
    public function requireSession(string $sessionId): array
    {
        return $this->require($sessionId);
    }

    public function delete(string $sessionId): void
    {
        $directory = $this->sessionDirectory($sessionId);
        if (!is_dir($directory)) {
            return;
        }

        // 先删子项，再删除 session 根目录。
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

    public function shouldGenerateTitle(string $sessionId): bool
    {
        $meta = $this->require($sessionId);

        return ($meta['title'] ?? '') === self::DEFAULT_TITLE
            && (bool) ($meta['title_generation_pending'] ?? false) === false;
    }

    public function markTitleGenerationPending(string $sessionId): bool
    {
        $meta = $this->require($sessionId);
        if (($meta['title'] ?? '') !== self::DEFAULT_TITLE) {
            return false;
        }

        if ((bool) ($meta['title_generation_pending'] ?? false) === true) {
            return false;
        }

        $meta['title_generation_pending'] = true;
        $this->writeMeta($sessionId, $meta);

        return true;
    }

    public function completeTitleGeneration(string $sessionId, string $title): bool
    {
        $title = $this->fallbackTitle($title);

        $meta = $this->require($sessionId);
        $meta['title_generation_pending'] = false;

        if (($meta['title'] ?? '') !== self::DEFAULT_TITLE) {
            $this->writeMeta($sessionId, $meta);
            return false;
        }

        $meta['title'] = $title;
        $meta['updated_at'] = time();
        $this->writeMeta($sessionId, $meta);

        return true;
    }

    public function fallbackTitle(string $candidate): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return self::DEFAULT_TITLE;
        }

        return mb_substr($candidate, 0, self::TITLE_LIMIT);
    }

    public function defaultTitle(): string
    {
        return self::DEFAULT_TITLE;
    }

    public function titleLimit(): int
    {
        return self::TITLE_LIMIT;
    }

    /**
     * @param PendingInterrupt|null $interrupt
     */
    public function setPendingInterrupt(string $sessionId, ?array $interrupt): void
    {
        $meta = $this->require($sessionId);
        $meta['pending_interrupt'] = $interrupt;
        $meta['updated_at'] = time();
        $this->writeMeta($sessionId, $meta);
    }

    /**
     * @return PendingInterrupt|null
     */
    public function getPendingInterrupt(string $sessionId): ?array
    {
        $meta = $this->require($sessionId);
        return $meta['pending_interrupt'] ?? null;
    }

    public function history(string $sessionId): FileChatHistory
    {
        return new FileChatHistory($this->sessionDirectory($sessionId), 'history', self::HISTORY_LIMIT, '', '.chat');
    }

    public function workflowPersistence(string $sessionId): FilePersistence
    {
        $directory = $this->workflowDirectory($sessionId);
        $this->ensureDirectory($directory);

        return new FilePersistence($directory, '', '.store');
    }

    public function workflowToken(string $sessionId): string
    {
        return self::WORKFLOW_TOKEN;
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
        $this->writeJsonFile($this->metaPath($sessionId), $meta);
    }

    /**
     * @return SessionMeta|null
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
     * @return SessionMeta
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
        // 所有运行期 session 状态都放在 Webman 的 runtime 目录下。
        $directory = runtime_path('sessions');
        $this->ensureDirectory($directory);

        return $directory;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create directory: ' . $directory);
        }
    }

    /**
     * @param SessionMeta $payload
     */
    private function writeJsonFile(string $path, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode session metadata.');
        }

        if (file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write file: ' . $path);
        }
    }
}
