<?php

declare(strict_types=1);

namespace app\neuron\agent;

use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tools\Toolkits\RetrievalTool;

class TravelAgent extends RAG
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

    protected function retrieval(): RetrievalInterface
    {
        return new SimilarityRetrieval(
            $this->resolveVectorStore(),
            $this->resolveEmbeddingsProvider()
        );
    }

    protected function provider(): AIProviderInterface
    {
        return new Deepseek(
            key: config('neuron.models.deepseek.key'),
            model: config('neuron.models.deepseek.model'),
            parameters: $this->providerParameters,
        );
    }

    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ["You are an AI Agent specialized in providing travel tips."],
        );
    }

    /**
     * @return ToolInterface[]|ToolkitInterface[]
     */
    protected function tools(): array
    {
        $tool = RetrievalTool::make(
            new SimilarityRetrieval(
                $this->vectorStore(), 
                $this->embeddings()
            )
        );
        array_push($this->customTools, $tool);
        return $this->customTools;
    }

    /**
     * @return array<class-string, mixed>
     */
    protected function middleware(): array
    {
        return $this->customMiddleware;
    }
    
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OllamaEmbeddingsProvider(
            url: config('neuron.models.embedding.url'),
            model: config('neuron.models.embedding.model')
        );
    }
    
    protected function vectorStore(): VectorStoreInterface
    {
        return new FileVectorStore(
            directory: base_path() . DIRECTORY_SEPARATOR . 'travel',
            name: 'travel_store',
        );
    }
}
