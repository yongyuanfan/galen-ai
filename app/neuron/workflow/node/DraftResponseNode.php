<?php

declare(strict_types=1);

namespace app\neuron\workflow\node;

use app\neuron\agent\DeepseekAgent;
use app\neuron\store\SessionStore;
use app\neuron\workflow\events\ToolExecutionCompletedEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

use function array_replace_recursive;

class DraftResponseNode extends Node
{
    public function __construct(private SessionStore $store)
    {
    }

    public function __invoke(ToolExecutionCompletedEvent $event, WorkflowState $state): StopEvent
    {
        $sessionId = (string) $state->get('session_id', '');
        $message = (string) $state->get('message', '');
        if ($sessionId === '' || $message === '') {
            return new StopEvent();
        }

        if ($event->intent === 'rename_file') {
            $result = (string) $state->get('tool_result', '文件重命名流程已结束。');
            $this->store->history($sessionId)->addMessage(new AssistantMessage($result));
            $state->set('final_answer', $result);
            return new StopEvent();
        }

        $instructions = $this->instructions(
            $event->intent,
            (string) $state->get('document_context', ''),
            (string) $state->get('document_name', '')
        );

        $reply = DeepseekAgent::make(
            customInstructions: $instructions,
            providerParameters: $this->providerParameters((bool) $state->get('deep_thinking', false)),
        )
            ->chat(new UserMessage($message))
            ->getMessage()
            ->getContent();

        $this->store->history($sessionId)->addMessage(new AssistantMessage($reply));
        $state->set('final_answer', $reply);

        return new StopEvent();
    }

    private function instructions(string $intent, string $documentContext, string $documentName): string
    {
        $base = [
            'You are Galen AI, a concise and practical assistant inside a Webman application.',
            'Answer in Chinese when the user uses Chinese, otherwise follow the user language.',
            'Keep answers directly useful and grounded in the available context.',
        ];

        if ($intent === 'document_qa') {
            if ($documentContext === '') {
                $base[] = 'If the user asks about an uploaded document but no context is available, ask them to upload a document first.';
            } else {
                $base[] = 'Use the provided document context as primary evidence. If context is insufficient, clearly state uncertainty.';
                $base[] = "Document name: {$documentName}";
                $base[] = "Document context:\n{$documentContext}";
            }
        }

        if ($intent === 'rename_file' || $intent === 'generate_file') {
            $base[] = 'If execution output is provided by workflow tools, present it clearly and do not fabricate extra operations.';
        }

        return implode("\n\n", $base);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerParameters(bool $deepThinking): array
    {
        $baseParameters = config('neuron.models.deepseek.chat_parameters', []);
        $thinkingParameters = config('neuron.models.deepseek.deep_thinking_parameters', []);

        return $deepThinking ? array_replace_recursive($baseParameters, $thinkingParameters) : $baseParameters;
    }
}
