<?php

declare(strict_types=1);

namespace app\neuron\service;

use app\neuron\agent\GenerateTitleAgent;
use app\neuron\store\SessionStore;

use NeuronAI\Chat\Messages\UserMessage;

use function array_filter;
use function explode;
use function is_string;
use function preg_match;
use function preg_replace;
use function preg_split;
use function str_contains;
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
- No quotes, labels, numbering, bullet points, markdown, or trailing punctuation
- Follow the user's language
- Summarize the main topic naturally instead of copying the full request
- For questions, extract the topic instead of keeping the question tone
- Keep it under 20 Chinese characters or 40 English characters
- If uncertain, return a short neutral title
- Never explain your choice
- Never output more than one candidate
PROMPT;

        $content = GenerateTitleAgent::make(
            customInstructions: $instructions,
            providerParameters: config('neuron.models.deepseek.chat_parameters', []),
        )
            ->chat(new UserMessage($message))
            ->getMessage()
            ->getContent();

        return $this->sanitize($content, $message);
    }

    private function sanitize(string $title, string $fallback): string
    {
        $title = $this->firstUsefulLine($title);
        $title = $this->stripFormatting($title);
        $title = preg_replace('/^["\'“”‘’「」『』《》【】()（）\[\]\s]+|["\'“”‘’「」『』《》【】()（）\[\]\s,.!?;:，。！？；：]+$/u', '', $title) ?? $title;

        if ($title === '') {
            return $this->store->fallbackTitle($fallback);
        }

        return $this->store->fallbackTitle($title);
    }

    private function firstUsefulLine(string $title): string
    {
        $parts = preg_split('/\R+/u', $title) ?: [];

        foreach ($parts as $part) {
            $part = $this->stripFormatting($part);
            if ($part === '') {
                continue;
            }

            if ($this->isBoilerplateLine($part)) {
                continue;
            }

            return $part;
        }

        return '';
    }

    private function stripFormatting(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/^```[a-z0-9_-]*|```$/iu', '', $title) ?? $title;
        $title = preg_replace('/\*\*(.*?)\*\*/u', '$1', $title) ?? $title;
        $title = preg_replace('/^#{1,6}\s+/u', '', $title) ?? $title;
        $title = preg_replace('/^[\-*+]\s+/u', '', $title) ?? $title;
        $title = preg_replace('/^\d+[.)、]\s+/u', '', $title) ?? $title;
        $title = preg_replace('/^(标题|题目|会话标题|建议标题)[:：]\s*/u', '', $title) ?? $title;
        $title = preg_replace('/^为您.*?(标题|题目)[：:]?/u', '', $title) ?? $title;

        return trim($title);
    }

    private function isBoilerplateLine(string $title): bool
    {
        if (preg_match('/^(thinking process|analyze|analysis|分析|说明|以下|下面|候选|可以考虑|请提供)/iu', $title) === 1) {
            return true;
        }

        return str_contains($title, '控制在') || str_contains($title, '以下几个标题');
    }
}
