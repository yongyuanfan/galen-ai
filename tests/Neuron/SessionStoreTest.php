<?php

declare(strict_types=1);

namespace tests\Neuron;

use app\neuron\SessionStore;
use PHPUnit\Framework\TestCase;

use function is_dir;
use function preg_match;

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
        self::assertMatchesRegularExpression('/^sess_[a-f0-9]{32}$/', $meta['id']);
        self::assertNull($meta['pending_interrupt']);
        self::assertIsInt($meta['created_at']);
        self::assertIsInt($meta['updated_at']);
        self::assertTrue(is_dir($this->store->sessionDirectory($meta['id'])));
        self::assertTrue(is_dir($this->store->docsDirectory($meta['id'])));

        $this->store->delete($meta['id']);
    }

    public function testUpdateTitleOnlyChangesDefaultTitle(): void
    {
        $meta = $this->store->create();
        $sessionId = $meta['id'];

        $this->store->updateTitleIfNeeded($sessionId, '这是第一次标题更新');
        $updated = $this->store->get($sessionId);

        self::assertSame('这是第一次标题更新', $updated['title']);

        $this->store->updateTitleIfNeeded($sessionId, '不会覆盖已存在标题');
        $updatedAgain = $this->store->get($sessionId);

        self::assertSame('这是第一次标题更新', $updatedAgain['title']);

        $this->store->delete($sessionId);
    }
}
