<?php

declare(strict_types=1);

namespace app\neuron\support;

use Workerman\Timer;

class AsyncDispatcher
{
    public function dispatch(callable $task): void
    {
        Timer::add(0.001, $task, [], false);
    }
}
