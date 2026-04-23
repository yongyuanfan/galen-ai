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

        try {
            $agent->chat(new UserMessage($message))->getMessage();
            $this->store->setPendingInterrupt($sessionId, null);
        } catch (WorkflowInterrupt $interrupt) {
            $request = $interrupt->getRequest();
            $this->store->setPendingInterrupt($sessionId, $request->jsonSerialize());

            return $this->sse([
                ...$this->renderer->render($this->store->history($sessionId)->getMessages()),
                [
                    'interruptRequest' => [
                        'interruptId' => $sessionId,
                        'description' => $request->getMessage(),
                    ],
                ],
            ]);
        }

        $this->store->touch($sessionId);

        return $this->sse($this->renderer->render($this->store->history($sessionId)->getMessages()));
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function streamChat(string $sessionId, string $message): Generator
    {
        $this->store->updateTitleIfNeeded($sessionId, $message);

        $agent = $this->factory->make($sessionId);
        $streamId = 'assistant_' . uniqid();
        $content = '';
        $started = false;

        try {
            foreach ($agent->stream(new UserMessage($message))->events() as $event) {
                if (!$event instanceof TextChunk) {
                    continue;
                }

                if (!$started) {
                    $started = true;
                    yield [
                        'assistantMessageStart' => [
                            'id' => $streamId,
                            'role' => 'Galen AI',
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

            foreach ($this->renderer->render($this->store->history($sessionId)->getMessages()) as $message) {
                yield $message;
            }
        } catch (WorkflowInterrupt $interrupt) {
            $request = $interrupt->getRequest();
            $this->store->setPendingInterrupt($sessionId, $request->jsonSerialize());

            foreach ($this->renderer->render($this->store->history($sessionId)->getMessages()) as $message) {
                yield $message;
            }

            yield [
                'interruptRequest' => [
                    'interruptId' => $sessionId,
                    'description' => $request->getMessage(),
                ],
            ];
        }
    }

    public function approve(string $sessionId, bool $approved, string $reason = ''): string
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

        $agent = $this->factory->make($sessionId);

        try {
            $agent->chat([], $request)->getMessage();
            $this->store->setPendingInterrupt($sessionId, null);
        } catch (WorkflowInterrupt $interrupt) {
            $next = $interrupt->getRequest();
            $this->store->setPendingInterrupt($sessionId, $next->jsonSerialize());

            return $this->sse([
                ...$this->renderer->render($this->store->history($sessionId)->getMessages()),
                [
                    'interruptRequest' => [
                        'interruptId' => $sessionId,
                        'description' => $next->getMessage(),
                    ],
                ],
            ]);
        }

        $this->store->touch($sessionId);

        return $this->sse($this->renderer->render($this->store->history($sessionId)->getMessages()));
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function streamApprove(string $sessionId, bool $approved, string $reason = ''): Generator
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

        $agent = $this->factory->make($sessionId);
        $streamId = 'assistant_' . uniqid();
        $content = '';
        $started = false;

        try {
            foreach ($agent->stream([], $request)->events() as $event) {
                if (!$event instanceof TextChunk) {
                    continue;
                }

                if (!$started) {
                    $started = true;
                    yield [
                        'assistantMessageStart' => [
                            'id' => $streamId,
                            'role' => 'Galen AI',
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

            foreach ($this->renderer->render($this->store->history($sessionId)->getMessages()) as $message) {
                yield $message;
            }
        } catch (WorkflowInterrupt $interrupt) {
            $next = $interrupt->getRequest();
            $this->store->setPendingInterrupt($sessionId, $next->jsonSerialize());

            foreach ($this->renderer->render($this->store->history($sessionId)->getMessages()) as $message) {
                yield $message;
            }

            yield [
                'interruptRequest' => [
                    'interruptId' => $sessionId,
                    'description' => $next->getMessage(),
                ],
            ];
        }
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
