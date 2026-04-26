<?php

declare(strict_types=1);

namespace app\neuron\factory;

use app\neuron\document\DocumentManager;
use app\neuron\store\SessionStore;
use app\neuron\workflow\SessionChatWorkflow;
use NeuronAI\Workflow\WorkflowState;

class SessionWorkflowFactory
{
    public function __construct(
        private SessionStore $store,
        private DocumentManager $documents,
    ) {
    }

    public function forChat(string $sessionId, string $message, bool $deepThinking = false): SessionChatWorkflow
    {
        $state = new WorkflowState([
            'session_id' => $sessionId,
            'message' => $message,
            'deep_thinking' => $deepThinking,
        ]);

        return new SessionChatWorkflow(
            $this->store,
            $this->documents,
            $this->store->workflowPersistence($sessionId),
            $this->store->workflowToken($sessionId),
            $state,
        );
    }

    public function forResume(string $sessionId): SessionChatWorkflow
    {
        return new SessionChatWorkflow(
            $this->store,
            $this->documents,
            $this->store->workflowPersistence($sessionId),
            $this->store->workflowToken($sessionId),
        );
    }
}
