<?php

declare(strict_types=1);

namespace tests\Neuron;

use app\neuron\DocumentManager;
use app\neuron\SessionStore;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

final class DocumentManagerTest extends TestCase
{
    private SessionStore $store;
    private DocumentManager $documents;

    protected function setUp(): void
    {
        $this->store = new SessionStore();
        $this->documents = new DocumentManager($this->store);
    }

    public function testResolvePathSupportsStoredNameAndLegacyPath(): void
    {
        $sessionId = $this->store->create()['id'];

        $resolved = $this->documents->resolvePath($sessionId, [
            'id' => 'doc_1',
            'name' => 'demo.txt',
            'stored_name' => 'demo.txt',
            'extension' => 'txt',
            'uploaded_at' => time(),
        ]);

        self::assertSame($this->store->docsDirectory($sessionId) . '/demo.txt', $resolved);

        $legacy = $this->documents->resolvePath($sessionId, [
            'id' => 'doc_2',
            'name' => 'legacy.txt',
            'path' => '/tmp/legacy.txt',
            'extension' => 'txt',
            'uploaded_at' => time(),
        ]);

        self::assertSame('/tmp/legacy.txt', $legacy);

        $this->store->delete($sessionId);
    }

    public function testExtractRelevantExcerptCentersAroundMatchedKeyword(): void
    {
        $sessionId = $this->store->create()['id'];
        $storedName = 'report.txt';
        $path = $this->store->docsDirectory($sessionId) . '/' . $storedName;
        $content = str_repeat('开头内容 ', 300) . '关键章节：血压明显升高，需要进一步观察。' . str_repeat(' 结尾内容', 300);
        file_put_contents($path, $content);

        $excerpt = $this->documents->extractRelevantExcerpt($sessionId, [
            'id' => 'doc_3',
            'name' => 'report.txt',
            'stored_name' => $storedName,
            'extension' => 'txt',
            'uploaded_at' => time(),
        ], '请总结血压情况', 80);

        self::assertStringContainsString('血压明显升高', $excerpt);
        self::assertStringContainsString('[truncated]', $excerpt);

        $this->store->delete($sessionId);
    }
}
