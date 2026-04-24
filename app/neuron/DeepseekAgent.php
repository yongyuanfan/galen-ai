<?php

declare(strict_types=1);

namespace app\neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

class DeepseekAgent extends Agent
{
    /**
     * @param ToolInterface[]|ToolkitInterface[] $customTools
     * @param array<class-string, mixed> $customMiddleware
     */
    public function __construct(
        protected array $customTools = [],
        protected ?string $customInstructions = null,
        protected array $customMiddleware = [],
        protected array $providerParameters = [],
        ...$arguments,
    ) {
        parent::__construct(...$arguments);
    }

    protected function provider(): AIProviderInterface
    {
        // 模型配置留在配置文件里，session 层只负责编排。
        return new Deepseek(
            key: config('neuron.agent.deepseek.key'),
            model: config('neuron.agent.deepseek.model'),
            parameters: $this->providerParameters,
        );
    }

    protected function instructions(): string
    {
        if ($this->customInstructions !== null) {
            return $this->customInstructions;
        }

        return (string) new SystemPrompt(
            background: ["You are a friendly AI Agent created with Neuron AI framework."],
        );
    }

    /**
     * @return ToolInterface[]|ToolkitInterface[]
     */
    protected function tools(): array
    {
        return $this->customTools;
    }

    /**
     * @return array<class-string, mixed>
     */
    protected function middleware(): array
    {
        return $this->customMiddleware;
    }
}
