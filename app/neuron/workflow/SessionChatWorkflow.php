<?php

declare(strict_types=1);

namespace app\neuron\workflow;

use app\neuron\document\DocumentManager;
use app\neuron\store\SessionStore;
use app\neuron\workflow\node\DraftResponseNode;
use app\neuron\workflow\node\ExecuteToolNode;
use app\neuron\workflow\node\HumanReviewNode;
use app\neuron\workflow\node\IntentClassifyNode;
use app\neuron\workflow\node\RetrieveContextNode;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

class SessionChatWorkflow extends Workflow
{
    public function __construct(
        private SessionStore $store,
        private DocumentManager $documents,
        ?PersistenceInterface $persistence = null,
        ?string $resumeToken = null,
        ?WorkflowState $state = null,
    ) {
        parent::__construct($persistence, $resumeToken, $state);
    }

    /**
     * @return NodeInterface[]
     */
    protected function nodes(): array
    {
        return [
            new IntentClassifyNode(),
            new RetrieveContextNode($this->documents),
            new HumanReviewNode($this->store),
            new ExecuteToolNode(),
            new DraftResponseNode($this->store),
        ];
    }
}
