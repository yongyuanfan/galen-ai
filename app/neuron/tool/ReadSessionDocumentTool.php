<?php

declare(strict_types=1);

namespace app\neuron\tool;

use app\neuron\DocumentManager;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class ReadSessionDocumentTool extends Tool
{
    private const EXCERPT_LIMIT = 12000;

    public function __construct(
        private readonly string $sessionId,
        private readonly DocumentManager $documents,
    ) {
        parent::__construct(
            'read_uploaded_document',
            'Read the latest uploaded document for this session and return the most relevant raw excerpt needed to answer the user.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'question',
                PropertyType::STRING,
                'The user question or the specific topic that should be looked up in the uploaded document.',
                true
            ),
        ];
    }

    public function __invoke(string $question): string
    {
        $document = $this->documents->latest($this->sessionId);
        if ($document === null) {
            return 'No document has been uploaded for this session.';
        }

        $excerpt = $this->documents->extractRelevantExcerpt($this->sessionId, $document, $question, self::EXCERPT_LIMIT);
        if ($excerpt === '') {
            return 'The uploaded document is empty.';
        }

        return "Document: {$document['name']}\nRequested topic: {$question}\n\n{$excerpt}";
    }
}
