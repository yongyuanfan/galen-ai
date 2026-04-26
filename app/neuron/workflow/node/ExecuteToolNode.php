<?php

declare(strict_types=1);

namespace app\neuron\workflow\node;

use app\neuron\tool\FileRenameTool;
use app\neuron\workflow\events\ReviewCompletedEvent;
use app\neuron\workflow\events\ToolExecutionCompletedEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

use function basename;
use function implode;
use function is_file;
use function preg_match;
use function realpath;
use function str_contains;
use function str_starts_with;
use function trim;

class ExecuteToolNode extends Node
{
    public function __construct(private FileRenameTool $renameTool = new FileRenameTool())
    {
    }

    public function __invoke(ReviewCompletedEvent $event, WorkflowState $state): ToolExecutionCompletedEvent
    {
        if ($event->intent !== 'rename_file') {
            $state->set('tool_name', null);
            $state->set('tool_result', null);
            $state->set('tool_success', null);
            return new ToolExecutionCompletedEvent($event->intent);
        }

        $message = (string) $state->get('message', '');
        $args = $this->extractRenameArgs($message);
        if ($args === null) {
            $state->set('tool_name', 'rename_file');
            $state->set('tool_success', false);
            $state->set('tool_result', '无法识别重命名参数。请提供源文件路径和新文件名。');
            return new ToolExecutionCompletedEvent($event->intent);
        }

        $resolved = $this->resolveSourcePath((string) $state->get('session_id', ''), $args['file_path']);
        if (!$resolved['ok']) {
            $state->set('tool_name', 'rename_file');
            $state->set('tool_success', false);
            $state->set('tool_result', $resolved['message']);
            return new ToolExecutionCompletedEvent($event->intent);
        }

        $newName = trim($args['new_name']);
        if (str_contains($newName, '/') || str_contains($newName, '\\')) {
            $newName = basename($newName);
        }

        $result = ($this->renameTool)($resolved['path'], $newName);
        $state->set('tool_name', 'rename_file');
        $state->set('tool_result', $result);
        $state->set(
            'tool_success',
            !str_contains($result, 'not found')
            && !str_contains($result, 'Unable')
            && !str_contains($result, 'required')
            && !str_contains($result, 'cannot')
            && !str_contains($result, 'exists')
            && !str_contains($result, 'outside workspace')
        );

        return new ToolExecutionCompletedEvent($event->intent);
    }

    /**
     * @return array{file_path: string, new_name: string}|null
     */
    private function extractRenameArgs(string $message): ?array
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        $quotedPatterns = [
            '/["“](.+?)["”]\s*(?:重命名为|改名为|改成|rename to|to)\s*["“](.+?)["”]/iu',
            "/['](.+?)[']\\s*(?:重命名为|改名为|改成|rename to|to)\\s*['](.+?)[']/iu",
        ];

        foreach ($quotedPatterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return [
                    'file_path' => trim($matches[1]),
                    'new_name' => trim($matches[2]),
                ];
            }
        }

        if (preg_match('/(\/[\w.\-\/]+)\s*(?:重命名为|改名为|改成|rename to|to)\s*([\w.\-]+)/iu', $message, $matches) === 1) {
            return [
                'file_path' => trim($matches[1]),
                'new_name' => trim($matches[2]),
            ];
        }

        return null;
    }

    /**
     * @return array{ok: bool, path: string, message: string}
     */
    private function resolveSourcePath(string $sessionId, string $rawPath): array
    {
        $rawPath = trim($rawPath);
        if ($rawPath === '') {
            return [
                'ok' => false,
                'path' => '',
                'message' => 'Source path is required.',
            ];
        }

        if (str_contains($rawPath, '..')) {
            return [
                'ok' => false,
                'path' => '',
                'message' => 'Path is outside workspace and is not allowed.',
            ];
        }

        $workspace = rtrim(base_path(), '/');
        $attempts = [];

        if ($this->isAbsolutePath($rawPath)) {
            if ($this->isExistingOutsideWorkspace($rawPath, $workspace)) {
                return [
                    'ok' => false,
                    'path' => '',
                    'message' => 'Path is outside workspace and is not allowed.',
                ];
            }

            if ($this->isFileWithinWorkspace($rawPath, $workspace)) {
                return [
                    'ok' => true,
                    'path' => $rawPath,
                    'message' => '',
                ];
            }

            $workspaceCandidate = $workspace . '/' . ltrim($rawPath, '/');
            $attempts[] = $workspaceCandidate;
            if ($this->isFileWithinWorkspace($workspaceCandidate, $workspace)) {
                return [
                    'ok' => true,
                    'path' => $workspaceCandidate,
                    'message' => '',
                ];
            }
        } else {
            $workspaceCandidate = $workspace . '/' . ltrim($rawPath, '/');
            $attempts[] = $workspaceCandidate;
            if ($this->isFileWithinWorkspace($workspaceCandidate, $workspace)) {
                return [
                    'ok' => true,
                    'path' => $workspaceCandidate,
                    'message' => '',
                ];
            }
        }

        if ($sessionId !== '') {
            $docsCandidate = $workspace . '/runtime/sessions/' . $sessionId . '/docs/' . basename($rawPath);
            $attempts[] = $docsCandidate;
            if ($this->isFileWithinWorkspace($docsCandidate, $workspace)) {
                return [
                    'ok' => true,
                    'path' => $docsCandidate,
                    'message' => '',
                ];
            }
        }

        return [
            'ok' => false,
            'path' => '',
            'message' => 'Source file not found in workspace. Tried paths: ' . implode(', ', $attempts),
        ];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/');
    }

    private function isExistingOutsideWorkspace(string $path, string $workspace): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $real = realpath($path);
        if ($real === false) {
            return true;
        }

        return !$this->isWithinWorkspace($real, $workspace);
    }

    private function isFileWithinWorkspace(string $path, string $workspace): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        return $this->isWithinWorkspace($real, $workspace);
    }

    private function isWithinWorkspace(string $path, string $workspace): bool
    {
        return $path === $workspace || str_starts_with($path, $workspace . '/');
    }
}
