<?php

declare(strict_types=1);

namespace tests\Neuron;

use app\neuron\ChatUiRenderer;
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
}
