<?php

declare(strict_types=1);

namespace app\neuron\workflow\node;

use app\neuron\store\SessionStore;
use app\neuron\workflow\events\ContextPreparedEvent;
use app\neuron\workflow\events\ReviewCompletedEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class HumanReviewNode extends Node
{
    private const REVIEW_ACTION_ID = 'review_request';

    public function __construct(private SessionStore $store)
    {
    }

    public function __invoke(ContextPreparedEvent $event, WorkflowState $state): ReviewCompletedEvent|StopEvent
    {
        if (!in_array($event->intent, ['rename_file', 'generate_file'], true)) {
            return new ReviewCompletedEvent($event->intent);
        }

        $message = (string) $state->get('message', '');
        $request = new ApprovalRequest(
            'This request may change local files. Please review and approve before continuing.',
            [
                new Action(
                    self::REVIEW_ACTION_ID,
                    'Review execution request',
                    $message !== '' ? $message : 'No request message was provided.'
                ),
            ]
        );

        $approval = $this->interrupt($request);
        if (!$approval instanceof ApprovalRequest) {
            return new ReviewCompletedEvent($event->intent);
        }

        $action = $approval->getAction(self::REVIEW_ACTION_ID);
        if ($action?->isApproved()) {
            return new ReviewCompletedEvent($event->intent);
        }

        $sessionId = (string) $state->get('session_id', '');
        $feedback = $action?->feedback !== null && $action->feedback !== ''
            ? $action->feedback
            : 'Request rejected by reviewer.';

        if ($sessionId !== '') {
            $this->store->history($sessionId)->addMessage(
                new AssistantMessage('已根据审批结果取消执行该请求：' . $feedback)
            );
        }

        $state->set('final_answer', 'Request rejected by reviewer.');

        return new StopEvent();
    }
}
