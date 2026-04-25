<?php

declare(strict_types=1);

namespace app\neuron;

use NeuronAI\Chat\Messages\UserMessage;

use function preg_replace;
use function trim;

class SessionTitleGenerator
{
    public function __construct(
        private SessionStore $store,
    ) {
    }

    public function generate(string $message): string
    {
        $instructions = <<<'PROMPT'
You generate concise chat session titles.

Rules:
- Output the title only
- No quotes, labels, numbering, or trailing punctuation
- Follow the user's language
- Summarize the main topic naturally instead of copying the full request
- Keep it under 20 Chinese characters or 40 English characters
PROMPT;

        $content = DeepseekAgent::make(
            customInstructions: $instructions,
            providerParameters: config('neuron.agent.deepseek.chat_parameters', []),
        )
            ->chat(new UserMessage($message))
            ->getMessage()
            ->getContent();

        return $this->sanitize($content, $message);
    }

    private function sanitize(string $title, string $fallback): string
    {
        $title = trim($title);
        $title = preg_replace('/^["\'“”‘’「」『』《》【】()（）\[\]\s]+|["\'“”‘’「」『』《》【】()（）\[\]\s,.!?;:，。！？；：]+$/u', '', $title) ?? $title;

        if ($title === '') {
            return $this->store->fallbackTitle($fallback);
        }

        return $this->store->fallbackTitle($title);
    }
}
