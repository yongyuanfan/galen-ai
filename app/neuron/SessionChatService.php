<?php

declare(strict_types=1);

namespace app\neuron;

use Generator;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

use function array_map;
use function json_encode;
use function uniqid;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

class SessionChatService
{
    private const ASSISTANT_ROLE = 'Galen AI';

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

    public function chat(string $sessionId, string $message): string
    {
        $this->store->updateTitleIfNeeded($sessionId, $message);
        $agent = $this->factory->make($sessionId);

        return $this->respondToInteraction(
            $sessionId,
            static fn () => $agent->chat(new UserMessage($message))->getMessage(),
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function streamChat(string $sessionId, string $message): Generator
    {
        $this->store->updateTitleIfNeeded($sessionId, $message);
        $agent = $this->factory->make($sessionId);

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
     * @return Generator<int, array<string, mixed>>
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

    private function respondToInteraction(string $sessionId, callable $operation): string
    {
        try {
            $operation();
            $this->store->setPendingInterrupt($sessionId, null);
            $this->store->touch($sessionId);

            return $this->sse($this->renderedHistory($sessionId));
        } catch (WorkflowInterrupt $interrupt) {
            return $this->sse($this->renderedHistoryWithInterrupt($sessionId, $interrupt->getRequest()));
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function streamInteraction(string $sessionId, callable $streamFactory): Generator
    {
        $streamId = 'assistant_' . uniqid();
        $content = '';
        $started = false;

        try {
            foreach ($streamFactory() as $event) {
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

                yield [
                    'assistantMessageDelta' => [
                        'id' => $streamId,
                        'content' => $content,
                    ],
                ];
            }

            $this->store->setPendingInterrupt($sessionId, null);
            $this->store->touch($sessionId);

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
     * @return array<int, array<string, mixed>>
     */
    private function renderedHistory(string $sessionId): array
    {
        return $this->renderer->render($this->store->history($sessionId)->getMessages());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function renderedHistoryWithInterrupt(string $sessionId, $request): array
    {
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
     * @param array<int, array<string, mixed>> $messages
     */
    private function jsonLines(array $messages): string
    {
        return implode("\n", array_map(
            static fn (array $item): string => (string) json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $messages,
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function sse(array $messages): string
    {
        return implode("\n\n", array_map(
            static fn (array $item): string => 'data: ' . json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $messages,
        )) . "\n\n";
    }
}
