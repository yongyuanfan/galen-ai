<?php

namespace app\command;

use app\neuron\DeepseekAgent;

use NeuronAI\Chat\Messages\UserMessage;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('chat', 'chat')]
class Chat extends Command
{
    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $message = DeepseekAgent::make()
            ->chat(new UserMessage("Hi, who are you?"))
            ->getMessage();

        echo $message->getContent();
        return self::SUCCESS;
    }
}
