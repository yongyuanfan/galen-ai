<?php

declare(strict_types=1);

namespace app\neuron;

use Generator;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

use function array_map;
use function json_encode;
use function uniqid;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @phpstan-type UiEvent array<string, array<string, mixed>>
 */
class SessionChatService
{
    private const ASSISTANT_ROLE = 'Galen AI';
    private const REASONING_ROLE = 'Deep Thinking';

    public function __construct(
        private SessionStore $store,
        private SessionAgentFactory $factory,
        private ChatUiRenderer $renderer,
    ) {
    }

    public function renderSession(string $sessionId): string
    {
        $history = $this->store->history($sessionId);
        return $this->jsonLines($this->renderer->render($history->getMessages()));
    }

    public function chat(string $sessionId, string $message, bool $deepThinking = false): string
    {
        $this->store->updateTitleIfNeeded($sessionId, $message);
        $agent = $this->factory->make($sessionId, $deepThinking);

        return $this->respondToInteraction(
            $sessionId,
            static fn () => $agent->chat(new UserMessage($message))->getMessage(),
        );
    }

    /**
     * @return Generator<int, UiEvent>
     */
    public function streamChat(string $sessionId, string $message, bool $deepThinking = false): Generator
    {
        $this->store->updateTitleIfNeeded($sessionId, $message);
        $agent = $this->factory->make($sessionId, $deepThinking);

        yield from $this->streamInteraction(
            $sessionId,
            static fn () => $agent->stream(new UserMessage($message))->events(),
        );
    }

    public function approve(string $sessionId, bool $approved, string $reason = ''): string
    {
        $request = $this->buildApprovalRequest($sessionId, $approved, $reason);
        $agent = $this->factory->make($sessionId);

        return $this->respondToInteraction(
            $sessionId,
            static fn () => $agent->chat([], $request)->getMessage(),
        );
    }

    /**
     * @return Generator<int, UiEvent>
     */
    public function streamApprove(string $sessionId, bool $approved, string $reason = ''): Generator
    {
        $request = $this->buildApprovalRequest($sessionId, $approved, $reason);
        $agent = $this->factory->make($sessionId);

        yield from $this->streamInteraction(
            $sessionId,
            static fn () => $agent->stream([], $request)->events(),
        );
    }

    /**
     * @param callable(): mixed $operation
     */
    private function respondToInteraction(string $sessionId, callable $operation): string
    {
        try {
            $operation();
            $this->store->setPendingInterrupt($sessionId, null);
            $this->store->touch($sessionId);

            return $this->sse($this->renderedHistory($sessionId));
        } catch (WorkflowInterrupt $interrupt) {
            // 中断请求也走同一条 UI 流，前端可以直接在对话里发起审批。
            return $this->sse($this->renderedHistoryWithInterrupt($sessionId, $interrupt->getRequest()));
        }
    }

    /**
     * @param callable(): iterable<int, mixed> $streamFactory
     * @return Generator<int, UiEvent>
     */
    private function streamInteraction(string $sessionId, callable $streamFactory): Generator
    {
        $streamId = 'assistant_' . uniqid();
        $reasoningId = 'reasoning_' . uniqid();
        $content = '';
        $reasoning = '';
        $started = false;
        $reasoningStarted = false;

        try {
            foreach ($streamFactory() as $event) {
                if ($event instanceof ReasoningChunk) {
                    if (!$reasoningStarted) {
                        $reasoningStarted = true;

                        yield [
                            'assistantReasoningStart' => [
                                'id' => $reasoningId,
                                'role' => self::REASONING_ROLE,
                            ],
                        ];
                    }

                    $reasoning .= $event->content;

                    yield [
                        'assistantReasoningDelta' => [
                            'id' => $reasoningId,
                            'content' => $reasoning,
                        ],
                    ];

                    continue;
                }

                if (!$event instanceof TextChunk) {
                    continue;
                }

                if (!$started) {
                    $started = true;

                    yield [
                        'assistantMessageStart' => [
                            'id' => $streamId,
                            'role' => self::ASSISTANT_ROLE,
                        ],
                    ];
                }

                $content .= $event->content;

                // 前端按整段内容覆盖草稿，因此这里持续返回累计后的文本。
                yield [
                    'assistantMessageDelta' => [
                        'id' => $streamId,
                        'content' => $content,
                    ],
                ];
            }

            $this->store->setPendingInterrupt($sessionId, null);
            $this->store->touch($sessionId);

            // 流式输出结束后回放持久化历史，确保界面状态与服务端一致。
            foreach ($this->renderedHistory($sessionId) as $message) {
                yield $message;
            }
        } catch (WorkflowInterrupt $interrupt) {
            foreach ($this->renderedHistoryWithInterrupt($sessionId, $interrupt->getRequest()) as $message) {
                yield $message;
            }
        }
    }

    private function buildApprovalRequest(string $sessionId, bool $approved, string $reason): ApprovalRequest
    {
        $pending = $this->store->getPendingInterrupt($sessionId);
        if ($pending === null) {
            throw new \RuntimeException('No pending approval for this session.');
        }

        $request = ApprovalRequest::fromArray($pending);
        foreach ($request->getActions() as $action) {
            if ($approved) {
                $action->approve($reason !== '' ? $reason : null);
                continue;
            }

            $action->reject($reason !== '' ? $reason : 'Rejected by user.');
        }

        return $request;
    }

    /**
     * @return array<int, UiEvent>
     */
    private function renderedHistory(string $sessionId): array
    {
        return $this->renderer->render($this->store->history($sessionId)->getMessages());
    }

    /**
     * @param object $request
     * @return array<int, UiEvent>
     */
    private function renderedHistoryWithInterrupt(string $sessionId, $request): array
    {
        // 先持久化审批载荷，后续 approve/reject 才能继续同一个工作流。
        $this->store->setPendingInterrupt($sessionId, $request->jsonSerialize());

        return [
            ...$this->renderedHistory($sessionId),
            [
                'interruptRequest' => [
                    'interruptId' => $sessionId,
                    'description' => $request->getMessage(),
                ],
            ],
        ];
    }

    /**
     * @param array<int, UiEvent> $messages
     */
    private function jsonLines(array $messages): string
    {
        return implode("\n", array_map(
            static fn (array $item): string => (string) json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $messages,
        ));
    }

    /**
     * @param array<int, UiEvent> $messages
     */
    private function sse(array $messages): string
    {
        return implode("\n\n", array_map(
            static fn (array $item): string => 'data: ' . json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $messages,
        )) . "\n\n";
    }
}
