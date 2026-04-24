<?php

declare(strict_types=1);

namespace app\neuron;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;

use function array_map;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @phpstan-type UiComponent array{
 *     id: string,
 *     component: array<string, array<string, mixed>>
 * }
 * @phpstan-type UiEvent array<string, array<string, mixed>>
 */
class ChatUiRenderer
{
    /**
     * @param Message[] $messages
     * @return array<int, UiEvent>
     */
    public function render(array $messages): array
    {
        $components = [];
        $cardIds = [];

        foreach ($messages as $index => $message) {
            // 每条消息都会展开成一个 Card 以及它的标题、正文节点。
            $cardId = 'card_' . $index;
            $columnId = 'card_col_' . $index;
            $captionId = 'caption_' . $index;
            $bodyId = 'body_' . $index;

            $cardIds[] = $cardId;

            $components[] = [
                'id' => $cardId,
                'component' => [
                    'Card' => [
                        'children' => [$columnId],
                    ],
                ],
            ];
            $components[] = [
                'id' => $columnId,
                'component' => [
                    'Column' => [
                        'children' => [$captionId, $bodyId],
                    ],
                ],
            ];
            $components[] = [
                'id' => $captionId,
                'component' => [
                    'Text' => [
                        'value' => $this->roleLabel($message),
                        'usageHint' => 'caption',
                    ],
                ],
            ];
            $components[] = [
                'id' => $bodyId,
                'component' => [
                    'Text' => [
                        'value' => $this->body($message),
                    ],
                ],
            ];
        }

        return [
            [
                'beginRendering' => [
                    'surfaceId' => 'chat',
                    'root' => 'root',
                ],
            ],
            [
                'surfaceUpdate' => [
                    'components' => [
                        [
                            'id' => 'root',
                            'component' => [
                                'Column' => [
                                    'children' => $cardIds,
                                ],
                            ],
                        ],
                        ...$components,
                    ],
                ],
            ],
        ];
    }

    private function roleLabel(Message $message): string
    {
        return match (true) {
            $message instanceof UserMessage => 'You',
            $message instanceof ToolCallMessage => 'tool call',
            $message instanceof ToolResultMessage => 'tool result',
            $message instanceof AssistantMessage => 'Galen AI',
            default => 'message',
        };
    }

    private function body(Message $message): string
    {
        return match (true) {
            // 工具调用结果转成 JSON，便于在聊天界面里直接查看和排查。
            $message instanceof ToolCallMessage => (string) json_encode($message->jsonSerialize()['tools'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            $message instanceof ToolResultMessage => (string) json_encode($message->jsonSerialize()['tools'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            default => $message->getContent(),
        };
    }
}
