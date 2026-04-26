<?php

declare(strict_types=1);

namespace tests\neuron\tool;

use app\neuron\store\SessionStore;
use app\neuron\tool\FileRenameTool;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function is_file;

final class FileRenameToolTest extends TestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        $this->store = new SessionStore();
    }

    public function testRenameFileWithinSameDirectory(): void
    {
        $sessionId = $this->store->create()['id'];
        $sourcePath = $this->store->docsDirectory($sessionId) . '/draft.txt';
        $targetPath = $this->store->docsDirectory($sessionId) . '/final.txt';
        file_put_contents($sourcePath, 'demo');

        $tool = new FileRenameTool();
        $result = $tool->__invoke($sourcePath, 'final.txt');

        self::assertSame("File renamed successfully: {$targetPath}", $result);
        self::assertFalse(is_file($sourcePath));
        self::assertTrue(is_file($targetPath));

        $this->store->delete($sessionId);
    }

    public function testSupportsToolSchemaNamedArguments(): void
    {
        $sessionId = $this->store->create()['id'];
        $sourcePath = $this->store->docsDirectory($sessionId) . '/draft.txt';
        $targetPath = $this->store->docsDirectory($sessionId) . '/final.txt';
        file_put_contents($sourcePath, 'demo');

        $tool = new FileRenameTool();
        $result = $tool->__invoke(file_path: $sourcePath, new_name: 'final.txt');

        self::assertSame("File renamed successfully: {$targetPath}", $result);
        self::assertFalse(is_file($sourcePath));
        self::assertTrue(is_file($targetPath));

        $this->store->delete($sessionId);
    }

    public function testRejectsDirectorySegmentsInNewName(): void
    {
        $sessionId = $this->store->create()['id'];
        $sourcePath = $this->store->docsDirectory($sessionId) . '/draft.txt';
        file_put_contents($sourcePath, 'demo');

        $tool = new FileRenameTool();
        $result = $tool->__invoke($sourcePath, 'nested/final.txt');

        self::assertSame('new_name must be a filename only and cannot include directory separators.', $result);
        self::assertTrue(is_file($sourcePath));

        $this->store->delete($sessionId);
    }

    public function testRejectsExistingTargetFile(): void
    {
        $sessionId = $this->store->create()['id'];
        $directory = $this->store->docsDirectory($sessionId);
        $sourcePath = $directory . '/draft.txt';
        $targetPath = $directory . '/final.txt';
        file_put_contents($sourcePath, 'demo');
        file_put_contents($targetPath, 'occupied');

        $tool = new FileRenameTool();
        $result = $tool->__invoke($sourcePath, 'final.txt');

        self::assertSame("Target file already exists: {$targetPath}", $result);
        self::assertTrue(is_file($sourcePath));
        self::assertTrue(is_file($targetPath));

        $this->store->delete($sessionId);
    }
}
