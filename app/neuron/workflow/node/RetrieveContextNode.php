<?php

declare(strict_types=1);

namespace app\neuron\workflow\node;

use app\neuron\document\DocumentManager;
use app\neuron\workflow\events\ContextPreparedEvent;
use app\neuron\workflow\events\IntentClassifiedEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class RetrieveContextNode extends Node
{
    public function __construct(private DocumentManager $documents)
    {
    }

    public function __invoke(IntentClassifiedEvent $event, WorkflowState $state): ContextPreparedEvent
    {
        if ($event->intent !== 'document_qa') {
            $state->set('document_context', '');
            return new ContextPreparedEvent($event->intent);
        }

        $sessionId = (string) $state->get('session_id', '');
        $message = (string) $state->get('message', '');
        if ($sessionId === '' || $message === '') {
            $state->set('document_context', '');
            return new ContextPreparedEvent($event->intent);
        }

        $document = $this->documents->latest($sessionId);
        if ($document === null) {
            $state->set('document_context', '');
            return new ContextPreparedEvent($event->intent);
        }

        $state->set('document_name', (string) ($document['name'] ?? 'document'));
        $state->set(
            'document_context',
            $this->documents->extractRelevantExcerpt($sessionId, $document, $message, 6000)
        );

        return new ContextPreparedEvent($event->intent);
    }
}
