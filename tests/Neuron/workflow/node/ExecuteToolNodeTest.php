<?php

declare(strict_types=1);

namespace tests\neuron\workflow\node;

use app\neuron\workflow\events\ReviewCompletedEvent;
use app\neuron\workflow\events\ToolExecutionCompletedEvent;
use app\neuron\workflow\node\ExecuteToolNode;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function base_path;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function unlink;

final class ExecuteToolNodeTest extends TestCase
{
    public function testRenameToolExecutesSuccessfullyWhenPathStartsWithSlash(): void
    {
        $oldPath = base_path() . '/travel_beijing.docx';
        $newPath = base_path() . '/travel_shanghai.docx';

        if (file_exists($newPath)) {
            unlink($newPath);
        }

        file_put_contents($oldPath, 'content');

        $state = new WorkflowState([
            'message' => '请把 "/travel_beijing.docx" 重命名为 "travel_shanghai.docx"',
        ]);

        $node = new ExecuteToolNode();
        $event = $node(new ReviewCompletedEvent('rename_file'), $state);

        self::assertInstanceOf(ToolExecutionCompletedEvent::class, $event);
        self::assertSame('rename_file', $event->intent);
        self::assertFalse(file_exists($oldPath));
        self::assertTrue(file_exists($newPath));
        self::assertTrue((bool) $state->get('tool_success'));
        self::assertStringContainsString('File renamed successfully', (string) $state->get('tool_result'));

        if (file_exists($newPath)) {
            unlink($newPath);
        }
    }

    public function testRenameToolResolvesBareFilenameFromSessionDocs(): void
    {
        $sessionId = 'sess_workflow_docs';
        $docsDir = base_path() . '/runtime/sessions/' . $sessionId . '/docs';
        if (!is_dir($docsDir)) {
            mkdir($docsDir, 0o755, true);
        }

        $oldPath = $docsDir . '/travel_beijing.docx';
        $newPath = $docsDir . '/travel_shanghai.docx';

        if (file_exists($newPath)) {
            unlink($newPath);
        }

        file_put_contents($oldPath, 'doc-content');

        $state = new WorkflowState([
            'session_id' => $sessionId,
            'message' => '请把 "travel_beijing.docx" 改名为 "travel_shanghai.docx"',
        ]);

        $node = new ExecuteToolNode();
        $event = $node(new ReviewCompletedEvent('rename_file'), $state);

        self::assertInstanceOf(ToolExecutionCompletedEvent::class, $event);
        self::assertTrue((bool) $state->get('tool_success'));
        self::assertFalse(file_exists($oldPath));
        self::assertTrue(file_exists($newPath));

        if (file_exists($newPath)) {
            unlink($newPath);
        }

        @rmdir($docsDir);
        @rmdir(base_path() . '/runtime/sessions/' . $sessionId);
    }

    public function testRenameToolStoresUserFacingErrorWhenParametersCannotBeParsed(): void
    {
        $state = new WorkflowState([
            'message' => '帮我重命名这个文件。',
        ]);

        $node = new ExecuteToolNode();
        $event = $node(new ReviewCompletedEvent('rename_file'), $state);

        self::assertInstanceOf(ToolExecutionCompletedEvent::class, $event);
        self::assertSame('rename_file', $event->intent);
        self::assertFalse((bool) $state->get('tool_success'));
        self::assertStringContainsString('无法识别重命名参数', (string) $state->get('tool_result'));
    }

    public function testRenameToolRejectsAbsolutePathOutsideWorkspace(): void
    {
        $state = new WorkflowState([
            'message' => '请把 "/etc/hosts" 重命名为 "hosts.bak"',
        ]);

        $node = new ExecuteToolNode();
        $event = $node(new ReviewCompletedEvent('rename_file'), $state);

        self::assertInstanceOf(ToolExecutionCompletedEvent::class, $event);
        self::assertFalse((bool) $state->get('tool_success'));
        self::assertStringContainsString('outside workspace', (string) $state->get('tool_result'));
    }

    public function testRenameToolReturnsTriedPathsWhenSourceMissing(): void
    {
        $state = new WorkflowState([
            'message' => '请把 "/definitely_missing_file_123456.docx" 重命名为 "new_name.docx"',
        ]);

        $node = new ExecuteToolNode();
        $event = $node(new ReviewCompletedEvent('rename_file'), $state);

        self::assertInstanceOf(ToolExecutionCompletedEvent::class, $event);
        self::assertFalse((bool) $state->get('tool_success'));
        self::assertStringContainsString('Tried paths:', (string) $state->get('tool_result'));
        self::assertStringContainsString(base_path() . '/definitely_missing_file_123456.docx', (string) $state->get('tool_result'));
    }
}
