<?php

declare(strict_types=1);

namespace tests\Neuron;

use app\neuron\ChatUiRenderer;
use app\neuron\DeepseekAgent;
use app\neuron\SessionAgentFactory;
use app\neuron\SessionChatService;
use app\neuron\SessionStore;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

final class SessionChatServiceTest extends TestCase
{
    public function testStreamChatYieldsDeltasAndReplaysPersistedHistory(): void
    {
        $store = $this->createMock(SessionStore::class);
        $factory = $this->createMock(SessionAgentFactory::class);
        $renderer = $this->createMock(ChatUiRenderer::class);
        $agent = $this->createMock(DeepseekAgent::class);
        $handler = $this->createMock(AgentHandler::class);
        $history = $this->createMock(FileChatHistory::class);

        $store->expects(self::once())->method('setPendingInterrupt')->with('sess_1', null);
        $store->expects(self::once())->method('touch')->with('sess_1');
        $store->expects(self::once())->method('history')->with('sess_1')->willReturn($history);

        $history->method('getMessages')->willReturn([]);
        $renderer->expects(self::once())->method('render')->with([])->willReturn([
            ['surfaceUpdate' => ['ok' => true]],
        ]);

        $factory->expects(self::once())->method('make')->with('sess_1', false)->willReturn($agent);
        $agent->expects(self::once())->method('stream')->willReturn($handler);
        $handler->expects(self::once())->method('events')->willReturn((function () {
            yield new TextChunk('message_1', '你');
            yield new TextChunk('message_1', '好');
        })());

        $service = new SessionChatService($store, $factory, $renderer);
        $events = iterator_to_array($service->streamChat('sess_1', '你好'), false);

        self::assertSame('assistantMessageStart', array_key_first($events[0]));
        self::assertSame('Galen AI', $events[0]['assistantMessageStart']['role']);
        self::assertSame('你', $events[1]['assistantMessageDelta']['content']);
        self::assertSame('你好', $events[2]['assistantMessageDelta']['content']);
        self::assertSame(['surfaceUpdate' => ['ok' => true]], $events[3]);
    }

    public function testStreamChatYieldsReasoningChunksWhenDeepThinkingIsEnabled(): void
    {
        $store = $this->createMock(SessionStore::class);
        $factory = $this->createMock(SessionAgentFactory::class);
        $renderer = $this->createMock(ChatUiRenderer::class);
        $agent = $this->createMock(DeepseekAgent::class);
        $handler = $this->createMock(AgentHandler::class);
        $history = $this->createMock(FileChatHistory::class);

        $store->expects(self::once())->method('setPendingInterrupt')->with('sess_reasoning', null);
        $store->expects(self::once())->method('touch')->with('sess_reasoning');
        $store->expects(self::once())->method('history')->with('sess_reasoning')->willReturn($history);

        $history->method('getMessages')->willReturn([]);
        $renderer->expects(self::once())->method('render')->with([])->willReturn([
            ['surfaceUpdate' => ['done' => true]],
        ]);

        $factory->expects(self::once())->method('make')->with('sess_reasoning', true)->willReturn($agent);
        $agent->expects(self::once())->method('stream')->willReturn($handler);
        $handler->expects(self::once())->method('events')->willReturn((function () {
            yield new ReasoningChunk('reason_1', '先分析病史');
            yield new ReasoningChunk('reason_1', '，再结合症状');
            yield new TextChunk('message_1', '最终');
            yield new TextChunk('message_1', '结论');
        })());

        $service = new SessionChatService($store, $factory, $renderer);
        $events = iterator_to_array($service->streamChat('sess_reasoning', '复杂问题', true), false);

        self::assertSame('assistantReasoningStart', array_key_first($events[0]));
        self::assertSame('深度思考', $events[0]['assistantReasoningStart']['role']);
        self::assertSame('先分析病史', $events[1]['assistantReasoningDelta']['content']);
        self::assertSame('先分析病史，再结合症状', $events[2]['assistantReasoningDelta']['content']);
        self::assertSame('assistantMessageStart', array_key_first($events[3]));
        self::assertSame('最终', $events[4]['assistantMessageDelta']['content']);
        self::assertSame('最终结论', $events[5]['assistantMessageDelta']['content']);
        self::assertSame(['surfaceUpdate' => ['done' => true]], $events[6]);
    }

    public function testChatPersistsInterruptAndReturnsInterruptEvent(): void
    {
        $store = $this->createMock(SessionStore::class);
        $factory = $this->createMock(SessionAgentFactory::class);
        $renderer = $this->createMock(ChatUiRenderer::class);
        $agent = $this->createMock(DeepseekAgent::class);
        $handler = $this->createMock(AgentHandler::class);
        $history = $this->createMock(FileChatHistory::class);
        $request = new ApprovalRequest('需要审批', [new Action('action_1', '读取文档')]);
        $interrupt = $this->workflowInterrupt($request);

        $store->expects(self::once())->method('setPendingInterrupt')->with('sess_2', $request->jsonSerialize());
        $store->expects(self::never())->method('touch');
        $store->expects(self::once())->method('history')->with('sess_2')->willReturn($history);

        $history->method('getMessages')->willReturn([]);
        $renderer->expects(self::once())->method('render')->with([])->willReturn([
            ['surfaceUpdate' => ['ok' => true]],
        ]);

        $factory->expects(self::once())->method('make')->with('sess_2', false)->willReturn($agent);
        $agent->expects(self::once())->method('chat')->willReturn($handler);
        $handler->expects(self::once())->method('getMessage')->willThrowException($interrupt);

        $service = new SessionChatService($store, $factory, $renderer);
        $payload = $service->chat('sess_2', '帮我看文档');

        self::assertStringContainsString('surfaceUpdate', $payload);
        self::assertStringContainsString('interruptRequest', $payload);
        self::assertStringContainsString('需要审批', $payload);
    }

    public function testApproveBuildsApprovedRequestBeforeContinuingWorkflow(): void
    {
        $store = $this->createMock(SessionStore::class);
        $factory = $this->createMock(SessionAgentFactory::class);
        $renderer = $this->createMock(ChatUiRenderer::class);
        $agent = $this->createMock(DeepseekAgent::class);
        $handler = $this->createMock(AgentHandler::class);
        $history = $this->createMock(FileChatHistory::class);
        $request = new ApprovalRequest('确认执行', [new Action('action_1', '读取文档')]);

        $store->expects(self::once())->method('getPendingInterrupt')->with('sess_3')->willReturn($request->jsonSerialize());
        $store->expects(self::once())->method('setPendingInterrupt')->with('sess_3', null);
        $store->expects(self::once())->method('touch')->with('sess_3');
        $store->expects(self::once())->method('history')->with('sess_3')->willReturn($history);

        $history->method('getMessages')->willReturn([]);
        $renderer->expects(self::once())->method('render')->with([])->willReturn([
            ['surfaceUpdate' => ['approved' => true]],
        ]);

        $factory->expects(self::once())->method('make')->with('sess_3', false)->willReturn($agent);
        $agent->expects(self::once())
            ->method('chat')
            ->with(
                [],
                self::callback(function (ApprovalRequest $approvedRequest): bool {
                    $action = $approvedRequest->getActions()[0];

                    return $action->isApproved() && $action->feedback === '允许执行';
                })
            )
            ->willReturn($handler);
        $handler->expects(self::once())->method('getMessage')->willReturn(new AssistantMessage('已执行'));

        $service = new SessionChatService($store, $factory, $renderer);
        $payload = $service->approve('sess_3', true, '允许执行');

        self::assertStringContainsString('approved', $payload);
    }

    private function workflowInterrupt(ApprovalRequest $request): WorkflowInterrupt
    {
        /** @var NodeInterface&MockObject $node */
        $node = $this->createMock(NodeInterface::class);
        /** @var Event&MockObject $event */
        $event = $this->createMock(Event::class);

        return new WorkflowInterrupt(
            $request,
            $node,
            new WorkflowState(['__workflowId' => 'workflow_1']),
            $event,
        );
    }
}
