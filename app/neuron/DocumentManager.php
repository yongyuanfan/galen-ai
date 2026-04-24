<?php

declare(strict_types=1);

namespace app\neuron;

use Webman\Http\UploadFile;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function mb_stripos;
use function mb_strtolower;
use function mb_substr;
use function pathinfo;
use function preg_split;
use function preg_replace;
use function strtolower;
use function time;
use function uniqid;

use const LOCK_EX;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

/**
 * @phpstan-type DocumentRecord array{
 *     id: string,
 *     name: string,
 *     stored_name?: string,
 *     path?: string,
 *     extension: string,
 *     uploaded_at: int
 * }
 */
class DocumentManager
{
    private const EXCERPT_LIMIT = 12000;
    private const EXCERPT_PADDING = 1500;

    public function __construct(private SessionStore $store)
    {
    }

    /**
     * @return DocumentRecord
     */
    public function save(string $sessionId, UploadFile $file): array
    {
        if (!$file->isValid()) {
            throw new \RuntimeException('Uploaded file is invalid.');
        }

        $originalName = $file->getUploadName() ?: 'document';
        $extension = strtolower($file->getUploadExtension());
        $basename = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'document';
        $storedName = uniqid($basename . '_', true) . ($extension !== '' ? '.' . $extension : '');
        $path = $this->documentPath($sessionId, $storedName);

        $file->move($path);

        $record = [
            'id' => uniqid('doc_'),
            'name' => $originalName,
            'stored_name' => $storedName,
            'extension' => $extension,
            'uploaded_at' => time(),
        ];

        $documents = $this->all($sessionId);
        $documents[] = $record;
        $this->write($sessionId, $documents);

        return $record;
    }

    /**
     * @return array<int, DocumentRecord>
     */
    public function all(string $sessionId): array
    {
        $file = $this->indexPath($sessionId);
        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }

        $documents = json_decode($raw, true);
        return is_array($documents) ? $documents : [];
    }

    /**
     * @return DocumentRecord|null
     */
    public function latest(string $sessionId): ?array
    {
        $documents = $this->all($sessionId);
        if ($documents === []) {
            return null;
        }

        return $documents[array_key_last($documents)];
    }

    public function extractText(string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Document file not found.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            try {
                return \NeuronAI\RAG\DataLoader\PdfReader::getText($path);
            } catch (\Throwable $exception) {
                return 'The uploaded PDF could not be parsed on the server. Please ask the user to upload a text, markdown, json, html, or doc converted to plain text.';
            }
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Unable to read document content.');
        }

        return $content;
    }

    /**
     * @param DocumentRecord $document
     */
    public function resolvePath(string $sessionId, array $document): string
    {
        $storedName = (string) ($document['stored_name'] ?? '');
        if ($storedName !== '') {
            return $this->documentPath($sessionId, $storedName);
        }

        // 兼容旧数据：历史记录直接保存了绝对路径。
        $legacyPath = (string) ($document['path'] ?? '');
        if ($legacyPath !== '') {
            return $legacyPath;
        }

        throw new \RuntimeException('Document path is missing.');
    }

    /**
     * @param DocumentRecord $document
     */
    public function extractRelevantExcerpt(string $sessionId, array $document, string $question, int $maxLength = self::EXCERPT_LIMIT): string
    {
        $content = trim($this->extractText($this->resolvePath($sessionId, $document)));
        if ($content === '') {
            return '';
        }

        if (mb_strlen($content) <= $maxLength) {
            return $content;
        }

        $normalizedQuestion = mb_strtolower(trim($question));
        $offset = $this->matchOffset($content, $normalizedQuestion);
        if ($offset === null) {
            $excerpt = mb_substr($content, 0, $maxLength);
            return $excerpt . "\n\n[truncated]";
        }

        // 以首个关键词命中点为中心截窗，尽量保留周边上下文。
        $padding = min(self::EXCERPT_PADDING, max(0, intdiv($maxLength, 2)));
        $start = max(0, $offset - $padding);
        $excerpt = mb_substr($content, $start, $maxLength);
        $end = $start + mb_strlen($excerpt);

        if ($start > 0) {
            $excerpt = "[truncated]\n\n" . $excerpt;
        }

        if ($end < mb_strlen($content)) {
            $excerpt .= "\n\n[truncated]";
        }

        return $excerpt;
    }

    /**
     * @param array<int, DocumentRecord> $documents
     */
    private function write(string $sessionId, array $documents): void
    {
        $encoded = json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode document metadata.');
        }

        if (file_put_contents($this->indexPath($sessionId), $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write document metadata.');
        }
    }

    private function indexPath(string $sessionId): string
    {
        return $this->store->sessionDirectory($sessionId) . '/documents.json';
    }

    private function documentPath(string $sessionId, string $storedName): string
    {
        return $this->store->docsDirectory($sessionId) . '/' . $storedName;
    }

    private function matchOffset(string $content, string $question): ?int
    {
        foreach ($this->keywords($question) as $keyword) {
            $position = mb_stripos($content, $keyword);
            if ($position !== false) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function keywords(string $question): array
    {
        $parts = preg_split('/[^\p{L}\p{N}_-]+/u', $question) ?: [];

        $keywords = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 2) {
                continue;
            }

            $keywords[] = $part;

            if (preg_match('/\p{Han}/u', $part) === 1 && mb_strlen($part) > 4) {
                for ($index = 0; $index <= mb_strlen($part) - 2; $index++) {
                    $keywords[] = mb_substr($part, $index, 2);
                }
            }
        }

        return array_values(array_unique($keywords));
    }
}
