<?php

declare(strict_types=1);

namespace app\neuron\workflow\node;

use app\neuron\workflow\events\IntentClassifiedEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class IntentClassifyNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): IntentClassifiedEvent
    {
        $message = (string) $state->get('message', '');

        $intent = match (true) {
            $this->contains($message, ['重命名', '改名', 'rename']) => 'rename_file',
            $this->contains($message, ['word', 'excel', 'ppt', 'docx', 'xlsx', 'pptx', '文档', '表格', '幻灯片']) => 'generate_file',
            $this->contains($message, ['document', 'uploaded', '文档', '上传', '附件']) => 'document_qa',
            default => 'general_chat',
        };

        $state->set('intent', $intent);

        return new IntentClassifiedEvent($intent);
    }

    /**
     * @param array<int, string> $keywords
     */
    private function contains(string $message, array $keywords): bool
    {
        $normalized = mb_strtolower($message);
        foreach ($keywords as $keyword) {
            if (mb_stripos($normalized, mb_strtolower($keyword)) !== false) {
                return true;
            }
        }

        return false;
    }
}
