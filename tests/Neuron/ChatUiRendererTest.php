<?php

declare(strict_types=1);

namespace tests\Neuron;

use app\neuron\ui\ChatUiRenderer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

final class ChatUiRendererTest extends TestCase
{
    public function testRenderBuildsSurfaceEventsForMessages(): void
    {
        $renderer = new ChatUiRenderer();

        $events = $renderer->render([
            new UserMessage('你好'),
            new AssistantMessage('你好，我可以帮你分析文档。'),
        ]);

        self::assertCount(2, $events);
        self::assertSame('chat', $events[0]['beginRendering']['surfaceId']);
        self::assertSame('root', $events[0]['beginRendering']['root']);
        self::assertSame(['card_0', 'card_1'], $events[1]['surfaceUpdate']['components'][0]['component']['Column']['children']);
    }

    public function testRenderIncludesReasoningCardBeforeAssistantMessage(): void
    {
        $renderer = new ChatUiRenderer();
        $assistant = new AssistantMessage('结论内容');
        $assistant->addMetadata('reasoning_content', '先分析症状，再结合化验结果。');

        $events = $renderer->render([
            new UserMessage('请详细分析'),
            $assistant,
        ]);

        $components = [];
        foreach ($events[1]['surfaceUpdate']['components'] as $component) {
            $components[$component['id']] = $component['component'];
        }

        self::assertSame(['card_0', 'card_1', 'card_2'], $components['root']['Column']['children']);
        self::assertSame('深度思考', $components['caption_1']['Text']['value']);
        self::assertSame('先分析症状，再结合化验结果。', $components['body_1']['Text']['value']);
        self::assertSame('Galen AI', $components['caption_2']['Text']['value']);
        self::assertSame('结论内容', $components['body_2']['Text']['value']);
    }
}
