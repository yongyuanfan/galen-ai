<?php

declare(strict_types=1);

namespace app\neuron\factory;

use app\neuron\agent\DeepseekAgent;
use app\neuron\document\DocumentManager;
use app\neuron\store\SessionStore;
use app\neuron\tool\FileRenameTool;
use app\neuron\tool\GenerateFileToolkit;
use app\neuron\tool\ReadSessionDocumentTool;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Agent\SystemPrompt;

use function array_replace_recursive;

class SessionAgentFactory
{
    public function __construct(
        private SessionStore $store,
        private DocumentManager $documents,
    ) {
    }

    public function make(string $sessionId, bool $deepThinking = false): DeepseekAgent
    {
        // 为当前请求恢复历史消息，让同一 session 能持续对话。
        $state = (new AgentState())->setChatHistory($this->store->history($sessionId));

        return DeepseekAgent::make(
            [
                new ReadSessionDocumentTool($sessionId, $this->documents),
                new FileRenameTool(),
                new GenerateFileToolkit(),
            ],
            $this->instructions($sessionId),
            // 读取上传文档、重命名文件和生成文件都需要显式审批。
            [
                ToolNode::class => [
                    new ToolApproval([
                        'read_uploaded_document',
                        'rename_file',
                        'generate_word_file',
                        'generate_excel_file',
                        'generate_ppt_file'
                    ]),
                ],
            ],
            $this->providerParameters($deepThinking),
            $this->store->workflowPersistence($sessionId),
            $this->store->workflowToken($sessionId),
            $state,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function providerParameters(bool $deepThinking): array
    {
        $baseParameters = config('neuron.agent.deepseek.chat_parameters', []);
        $thinkingParameters = config('neuron.agent.deepseek.deep_thinking_parameters', []);

        return $deepThinking ? array_replace_recursive($baseParameters, $thinkingParameters) : $baseParameters;
    }

    private function instructions(string $sessionId): string
    {
        $hasDocument = $this->documents->latest($sessionId) !== null;

        return (string) new SystemPrompt(
            background: [
                'You are Galen AI, a concise and practical assistant inside a Webman application.',
                'If the user asks about an uploaded document, answer based on the document content instead of making assumptions.',
            ],
            steps: [
                $hasDocument
                    ? 'A document is available in this session. Use the read_uploaded_document tool before answering any document-specific question.'
                    : 'If the user asks about a document but none has been uploaded, tell them to upload one first.',
                'If the user asks to rename a file, use the rename_file tool with the exact source path and the target filename only.',
                'Answer in Chinese when the user uses Chinese, otherwise follow the user language.',
            ],
            output: [
                'Keep answers directly useful and grounded in the available context.',
            ],
        );
    }
}
