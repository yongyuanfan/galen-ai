<?php

declare(strict_types=1);

namespace app\neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;

class OllamaTitleAgent extends Agent
{
    public function __construct(
        protected ?string $customInstructions = null,
        protected array $providerParameters = [],
        ...$arguments,
    ) {
        parent::__construct(...$arguments);
    }

    protected function provider(): AIProviderInterface
    {
        return new Ollama(
            url: config('neuron.models.ollama.title.url'),
            model: config('neuron.models.ollama.title.model'),
            parameters: $this->providerParameters,
        );
    }

    protected function instructions(): string
    {
        if ($this->customInstructions !== null) {
            return $this->customInstructions;
        }

        return (string) new SystemPrompt(
            background: ['You generate concise chat session titles.'],
        );
    }
}
