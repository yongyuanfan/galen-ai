<?php

declare(strict_types=1);

namespace tests\neuron\store;

use app\neuron\store\SessionStore;
use PHPUnit\Framework\TestCase;

use function is_dir;

final class SessionStoreTest extends TestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        $this->store = new SessionStore();
    }

    public function testCreateBuildsSessionMetadataAndDirectories(): void
    {
        $meta = $this->store->create();

        self::assertSame('New Session', $meta['title']);
        self::assertFalse($meta['title_generation_pending']);
        self::assertMatchesRegularExpression('/^sess_[a-f0-9]{32}$/', $meta['id']);
        self::assertNull($meta['pending_interrupt']);
        self::assertIsInt($meta['created_at']);
        self::assertIsInt($meta['updated_at']);
        self::assertTrue(is_dir($this->store->sessionDirectory($meta['id'])));
        self::assertTrue(is_dir($this->store->docsDirectory($meta['id'])));

        $this->store->delete($meta['id']);
    }

    public function testTitleGenerationOnlyChangesDefaultTitleOnce(): void
    {
        $meta = $this->store->create();
        $sessionId = $meta['id'];

        self::assertTrue($this->store->shouldGenerateTitle($sessionId));
        self::assertTrue($this->store->markTitleGenerationPending($sessionId));
        self::assertFalse($this->store->markTitleGenerationPending($sessionId));

        $pending = $this->store->get($sessionId);
        self::assertTrue($pending['title_generation_pending']);

        self::assertTrue($this->store->completeTitleGeneration($sessionId, '这是第一次标题更新'));

        $updated = $this->store->get($sessionId);
        self::assertSame('这是第一次标题更新', $updated['title']);
        self::assertFalse($updated['title_generation_pending']);

        self::assertFalse($this->store->completeTitleGeneration($sessionId, '不会覆盖已存在标题'));
        $updatedAgain = $this->store->get($sessionId);

        self::assertSame('这是第一次标题更新', $updatedAgain['title']);

        $this->store->delete($sessionId);
    }

    public function testFallbackTitleTruncatesAndFallsBackToDefaultWhenEmpty(): void
    {
        self::assertSame('New Session', $this->store->fallbackTitle('   '));
        self::assertSame(
            '1234567890123456789012345678901234567890',
            $this->store->fallbackTitle('123456789012345678901234567890123456789012345')
        );
    }
}
