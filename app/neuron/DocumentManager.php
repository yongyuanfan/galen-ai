<?php

declare(strict_types=1);

namespace app\neuron;

use Webman\Http\UploadFile;

use function array_map;
use function file_exists;
use function file_get_contents;
use function is_file;
use function json_decode;
use function json_encode;
use function pathinfo;
use function preg_replace;
use function str_ends_with;
use function strtolower;
use function time;
use function uniqid;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PATHINFO_BASENAME;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

class DocumentManager
{
    public function __construct(private SessionStore $store)
    {
    }

    /**
     * @return array<string, mixed>
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
        $path = $this->store->docsDirectory($sessionId) . '/' . $storedName;

        $file->move($path);

        $record = [
            'id' => uniqid('doc_'),
            'name' => $originalName,
            'path' => $path,
            'extension' => $extension,
            'uploaded_at' => time(),
        ];

        $documents = $this->all($sessionId);
        $documents[] = $record;
        $this->write($sessionId, $documents);

        return $record;
    }

    /**
     * @return array<int, array<string, mixed>>
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
     * @return array<string, mixed>|null
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
     * @param array<int, array<string, mixed>> $documents
     */
    private function write(string $sessionId, array $documents): void
    {
        file_put_contents(
            $this->indexPath($sessionId),
            json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    private function indexPath(string $sessionId): string
    {
        return $this->store->sessionDirectory($sessionId) . '/documents.json';
    }
}
