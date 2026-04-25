#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

final class FilesystemTools
{
    private string $root;

    public function __construct(string $root)
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new RuntimeException('Invalid filesystem root directory.');
        }

        $this->root = rtrim($realRoot, DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string, mixed>
     */
    public function listDirectory(string $path = '.', bool $recursive = false, int $maxEntries = 500): array
    {
        if ($maxEntries < 1 || $maxEntries > 5000) {
            throw new InvalidArgumentException('maxEntries must be between 1 and 5000.');
        }

        $directory = $this->resolvePath($path);
        if (!is_dir($directory)) {
            throw new RuntimeException('The specified path is not a directory.');
        }

        $items = [];
        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $entry) {
                $items[] = $this->formatEntry($entry->getPathname());
                if (count($items) >= $maxEntries) {
                    break;
                }
            }
        } else {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $entry) {
                $items[] = $this->formatEntry($entry->getPathname());
                if (count($items) >= $maxEntries) {
                    break;
                }
            }
        }

        return [
            'root' => $this->root,
            'path' => $this->toRelativePath($directory),
            'recursive' => $recursive,
            'count' => count($items),
            'truncated' => count($items) >= $maxEntries,
            'entries' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function readFile(string $path, int $maxBytes = 1048576): array
    {
        if ($maxBytes < 1 || $maxBytes > 10485760) {
            throw new InvalidArgumentException('maxBytes must be between 1 and 10485760.');
        }

        $file = $this->resolvePath($path);
        if (!is_file($file)) {
            throw new RuntimeException('The specified path is not a file.');
        }
        if (!is_readable($file)) {
            throw new RuntimeException('The specified file is not readable.');
        }

        $size = filesize($file);
        if ($size === false) {
            throw new RuntimeException('Unable to determine file size.');
        }

        $truncated = $size > $maxBytes;
        if (!$truncated) {
            $content = file_get_contents($file);
            if ($content === false) {
                throw new RuntimeException('Unable to read file content.');
            }
        } else {
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open file for reading.');
            }
            $content = fread($handle, $maxBytes);
            fclose($handle);
            if ($content === false) {
                throw new RuntimeException('Unable to read file content.');
            }
        }

        return [
            'path' => $this->toRelativePath($file),
            'size' => $size,
            'bytes_returned' => strlen($content),
            'truncated' => $truncated,
            'content' => $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function writeFile(string $path, string $content, string $mode = 'overwrite', bool $createDirs = false): array
    {
        if ($mode !== 'overwrite' && $mode !== 'append') {
            throw new InvalidArgumentException('mode must be either "overwrite" or "append".');
        }

        $target = $this->resolvePath($path, true);
        if (is_dir($target)) {
            throw new RuntimeException('Cannot write to a directory path.');
        }

        $parent = dirname($target);
        if (!is_dir($parent)) {
            if (!$createDirs) {
                throw new RuntimeException('Parent directory does not exist. Set createDirs=true to create it.');
            }
            if (!mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Unable to create parent directories.');
            }
        }

        if (!is_writable($parent)) {
            throw new RuntimeException('Parent directory is not writable.');
        }

        $flags = $mode === 'append' ? FILE_APPEND : 0;
        $bytes = file_put_contents($target, $content, $flags);
        if ($bytes === false) {
            throw new RuntimeException('Failed to write file content.');
        }

        $size = filesize($target);

        return [
            'path' => $this->toRelativePath($target),
            'mode' => $mode,
            'bytes_written' => $bytes,
            'size' => $size === false ? null : $size,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createDirectory(string $path, bool $recursive = true): array
    {
        $target = $this->resolvePath($path, true);

        if (file_exists($target)) {
            if (!is_dir($target)) {
                throw new RuntimeException('Target path exists and is not a directory.');
            }

            return [
                'path' => $this->toRelativePath($target),
                'created' => false,
                'exists' => true,
            ];
        }

        if (!mkdir($target, 0775, $recursive) && !is_dir($target)) {
            throw new RuntimeException('Unable to create directory.');
        }

        return [
            'path' => $this->toRelativePath($target),
            'created' => true,
            'exists' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statPath(string $path): array
    {
        $target = $this->resolvePath($path, true);

        if (!file_exists($target) && !is_link($target)) {
            return [
                'path' => $this->toRelativePath($target),
                'exists' => false,
            ];
        }

        $stat = @stat($target);
        if ($stat === false) {
            throw new RuntimeException('Unable to get file status.');
        }

        $type = is_link($target)
            ? 'link'
            : (is_dir($target) ? 'directory' : (is_file($target) ? 'file' : 'other'));

        return [
            'path' => $this->toRelativePath($target),
            'exists' => true,
            'type' => $type,
            'size' => $stat['size'],
            'permissions' => substr(sprintf('%o', fileperms($target) ?: 0), -4),
            'is_readable' => is_readable($target),
            'is_writable' => is_writable($target),
            'modified_at' => $stat['mtime'],
            'created_at' => $stat['ctime'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(string $path): array
    {
        $type = is_link($path)
            ? 'link'
            : (is_dir($path) ? 'directory' : (is_file($path) ? 'file' : 'other'));

        return [
            'path' => $this->toRelativePath($path),
            'type' => $type,
            'size' => is_file($path) ? (filesize($path) ?: 0) : null,
            'modified_at' => filemtime($path) ?: null,
        ];
    }

    private function resolvePath(string $path, bool $allowNonExistent = false): string
    {
        $relative = $this->normalizeRelativePath($path);
        $candidate = $relative === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . $relative;

        if (!$allowNonExistent) {
            $resolved = realpath($candidate);
            if ($resolved === false) {
                throw new RuntimeException('Path does not exist.');
            }
            if (!$this->isWithinRoot($resolved)) {
                throw new RuntimeException('Path is outside of allowed root.');
            }

            return $resolved;
        }

        $existing = $candidate;
        while (!file_exists($existing) && !is_link($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                throw new RuntimeException('Unable to resolve path.');
            }
            $existing = $parent;
        }

        $resolvedExisting = realpath($existing);
        if ($resolvedExisting === false || !$this->isWithinRoot($resolvedExisting)) {
            throw new RuntimeException('Path is outside of allowed root.');
        }

        return $candidate;
    }

    private function normalizeRelativePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === '.') {
            return '';
        }

        if (str_contains($trimmed, "\0")) {
            throw new InvalidArgumentException('Path contains invalid null bytes.');
        }

        $normalized = str_replace('\\', '/', $trimmed);

        if (str_starts_with($normalized, '/')) {
            throw new InvalidArgumentException('Absolute paths are not allowed.');
        }

        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            throw new InvalidArgumentException('Absolute paths are not allowed.');
        }

        $parts = explode('/', $normalized);
        $safe = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($safe === []) {
                    throw new InvalidArgumentException('Path traversal is not allowed.');
                }
                array_pop($safe);
                continue;
            }

            $safe[] = $part;
        }

        return implode(DIRECTORY_SEPARATOR, $safe);
    }

    private function isWithinRoot(string $absolutePath): bool
    {
        return $absolutePath === $this->root || str_starts_with($absolutePath, $this->root . DIRECTORY_SEPARATOR);
    }

    private function toRelativePath(string $absolutePath): string
    {
        if ($absolutePath === $this->root) {
            return '.';
        }

        if (str_starts_with($absolutePath, $this->root . DIRECTORY_SEPARATOR)) {
            return substr($absolutePath, strlen($this->root) + 1);
        }

        return $absolutePath;
    }
}

$root = getenv('FILESYSTEM_ROOT') ?: __DIR__;
$tools = new FilesystemTools($root);

$server = Server::builder()
    ->setServerInfo('Local Filesystem Server', '1.0.0', 'MCP server for safe local filesystem access.')
    ->setInstructions('All paths must be relative to the configured root. This server supports list, read, write, mkdir and stat operations.')
    ->addTool(
        static fn (string $path = '.', bool $recursive = false, int $maxEntries = 500): array => $tools->listDirectory($path, $recursive, $maxEntries),
        'list_directory',
        'List files and directories under the allowed root.'
    )
    ->addTool(
        static fn (string $path, int $maxBytes = 1048576): array => $tools->readFile($path, $maxBytes),
        'read_file',
        'Read a text file under the allowed root.'
    )
    ->addTool(
        static fn (string $path, string $content, string $mode = 'overwrite', bool $createDirs = false): array => $tools->writeFile($path, $content, $mode, $createDirs),
        'write_file',
        'Write or append text content to a file under the allowed root.'
    )
    ->addTool(
        static fn (string $path, bool $recursive = true): array => $tools->createDirectory($path, $recursive),
        'create_directory',
        'Create a directory under the allowed root.'
    )
    ->addTool(
        static fn (string $path): array => $tools->statPath($path),
        'stat_path',
        'Get metadata for a file or directory under the allowed root.'
    )
    ->build();

$server->run(new StdioTransport());
