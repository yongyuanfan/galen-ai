<?php

declare(strict_types=1);

namespace app\neuron\workflow\events;

use NeuronAI\Workflow\Events\Event;

class ToolExecutionCompletedEvent implements Event
{
    public function __construct(public readonly string $intent)
    {
    }
}
