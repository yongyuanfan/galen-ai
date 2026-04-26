<?php

declare(strict_types=1);

namespace tests\neuron\service;

use app\neuron\generator\SessionTitleGenerator;
use app\neuron\service\SessionTitleService;
use app\neuron\store\SessionStore;
use PHPUnit\Framework\TestCase;

final class SessionTitleServiceTest extends TestCase
{
    public function testGenerateNowIfNeededReturnsGeneratedTitleImmediately(): void
    {
        $store = $this->createMock(SessionStore::class);
        $generator = $this->createMock(SessionTitleGenerator::class);

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

        $service = new SessionTitleService($store, $generator);

        self::assertSame($updated, $service->generateNowIfNeeded('sess_4', '帮我分析体检报告'));
    }

    public function testGenerateNowIfNeededSkipsWhenTitleAlreadyExists(): void
    {
        $store = $this->createMock(SessionStore::class);
        $generator = $this->createMock(SessionTitleGenerator::class);
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

        $service = new SessionTitleService($store, $generator);

        self::assertSame($current, $service->generateNowIfNeeded('sess_5', '帮我分析体检报告'));
    }
}
