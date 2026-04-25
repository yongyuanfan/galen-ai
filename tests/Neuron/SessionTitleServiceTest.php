<?php

declare(strict_types=1);

namespace tests\Neuron;

use app\neuron\service\SessionTitleGenerator;
use app\neuron\service\SessionTitleService;
use app\neuron\store\SessionStore;
use app\neuron\support\AsyncDispatcher;
use PHPUnit\Framework\TestCase;

final class SessionTitleServiceTest extends TestCase
{
    public function testQueueGenerationDispatchesAsyncWorkForDefaultTitle(): void
    {
        $store = $this->createMock(SessionStore::class);
        $generator = $this->createMock(SessionTitleGenerator::class);
        $dispatcher = $this->createMock(AsyncDispatcher::class);

        $store->expects(self::once())->method('fallbackTitle')->with('请帮我分析这个检查报告')->willReturn('请帮我分析这个检查报告');
        $store->expects(self::once())->method('defaultTitle')->willReturn('New Session');
        $store->expects(self::once())->method('markTitleGenerationPending')->with('sess_1')->willReturn(true);
        $generator->expects(self::once())->method('generate')->with('请帮我分析这个检查报告')->willReturn('检查报告分析');
        $store->expects(self::once())->method('completeTitleGeneration')->with('sess_1', '检查报告分析');

        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(static function (callable $task): bool {
            $task();

            return true;
        }));

        $service = new SessionTitleService($store, $generator, $dispatcher);
        $service->queueGenerationIfNeeded('sess_1', '请帮我分析这个检查报告');
    }

    public function testQueueGenerationFallsBackToTruncatedMessageWhenModelFails(): void
    {
        $store = $this->createMock(SessionStore::class);
        $generator = $this->createMock(SessionTitleGenerator::class);
        $dispatcher = $this->createMock(AsyncDispatcher::class);

        $store->expects(self::exactly(2))->method('fallbackTitle')->willReturnMap([
            ['这是一条特别长的首条消息', '这是一条特别长的首条消息'],
            ['这是一条特别长的首条消息', '这是一条特别长的首条消息'],
        ]);
        $store->expects(self::once())->method('defaultTitle')->willReturn('New Session');
        $store->expects(self::once())->method('markTitleGenerationPending')->with('sess_2')->willReturn(true);
        $generator->expects(self::once())->method('generate')->with('这是一条特别长的首条消息')->willThrowException(new \RuntimeException('provider error'));
        $store->expects(self::once())->method('completeTitleGeneration')->with('sess_2', '这是一条特别长的首条消息');

        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(static function (callable $task): bool {
            $task();

            return true;
        }));

        $service = new SessionTitleService($store, $generator, $dispatcher);
        $service->queueGenerationIfNeeded('sess_2', '这是一条特别长的首条消息');
    }

    public function testQueueGenerationSkipsWhenMessageFallsBackToDefaultTitle(): void
    {
        $store = $this->createMock(SessionStore::class);
        $generator = $this->createMock(SessionTitleGenerator::class);
        $dispatcher = $this->createMock(AsyncDispatcher::class);

        $store->expects(self::once())->method('fallbackTitle')->with('   ')->willReturn('New Session');
        $store->expects(self::once())->method('defaultTitle')->willReturn('New Session');
        $store->expects(self::never())->method('markTitleGenerationPending');
        $generator->expects(self::never())->method('generate');
        $dispatcher->expects(self::never())->method('dispatch');

        $service = new SessionTitleService($store, $generator, $dispatcher);
        $service->queueGenerationIfNeeded('sess_3', '   ');
    }

    public function testGenerateNowIfNeededReturnsGeneratedTitleImmediately(): void
    {
        $store = $this->createMock(SessionStore::class);
        $generator = $this->createMock(SessionTitleGenerator::class);
        $dispatcher = $this->createMock(AsyncDispatcher::class);

        $current = [
            'id' => 'sess_4',
            'title' => 'New Session',
            'created_at' => 1,
            'updated_at' => 1,
            'title_generation_pending' => false,
            'pending_interrupt' => null,
        ];
        $updated = $current;
        $updated['title'] = '体检报告分析';
        $updated['updated_at'] = 2;

        $store->expects(self::once())->method('fallbackTitle')->with('帮我分析体检报告')->willReturn('帮我分析体检报告');
        $store->expects(self::exactly(2))->method('defaultTitle')->willReturn('New Session');
        $store->expects(self::exactly(2))->method('requireSession')->with('sess_4')->willReturnOnConsecutiveCalls($current, $updated);
        $store->expects(self::once())->method('markTitleGenerationPending')->with('sess_4')->willReturn(true);
        $generator->expects(self::once())->method('generate')->with('帮我分析体检报告')->willReturn('体检报告分析');
        $store->expects(self::once())->method('completeTitleGeneration')->with('sess_4', '体检报告分析');
        $dispatcher->expects(self::never())->method('dispatch');

        $service = new SessionTitleService($store, $generator, $dispatcher);

        self::assertSame($updated, $service->generateNowIfNeeded('sess_4', '帮我分析体检报告'));
    }

    public function testGenerateNowIfNeededSkipsWhenTitleAlreadyExists(): void
    {
        $store = $this->createMock(SessionStore::class);
        $generator = $this->createMock(SessionTitleGenerator::class);
        $dispatcher = $this->createMock(AsyncDispatcher::class);
        $current = [
            'id' => 'sess_5',
            'title' => '已存在标题',
            'created_at' => 1,
            'updated_at' => 2,
            'title_generation_pending' => false,
            'pending_interrupt' => null,
        ];

        $store->expects(self::once())->method('fallbackTitle')->with('帮我分析体检报告')->willReturn('帮我分析体检报告');
        $store->expects(self::exactly(2))->method('defaultTitle')->willReturn('New Session');
        $store->expects(self::once())->method('requireSession')->with('sess_5')->willReturn($current);
        $store->expects(self::never())->method('markTitleGenerationPending');
        $generator->expects(self::never())->method('generate');
        $store->expects(self::never())->method('completeTitleGeneration');
        $dispatcher->expects(self::never())->method('dispatch');

        $service = new SessionTitleService($store, $generator, $dispatcher);

        self::assertSame($current, $service->generateNowIfNeeded('sess_5', '帮我分析体检报告'));
    }
}
