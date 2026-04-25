<?php

declare(strict_types=1);

namespace tests\Neuron;

use app\neuron\AsyncDispatcher;
use app\neuron\SessionStore;
use app\neuron\SessionTitleGenerator;
use app\neuron\SessionTitleService;
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
}
