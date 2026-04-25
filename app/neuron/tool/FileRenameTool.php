<?php

declare(strict_types=1);

namespace app\neuron\tool;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function basename;
use function dirname;
use function file_exists;
use function is_file;
use function rename;
use function str_contains;
use function trim;

class FileRenameTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'rename_file',
            'Rename an existing local file in place by changing only its filename within the same directory.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'file_path',
                PropertyType::STRING,
                'Absolute or relative path of the existing file that should be renamed.',
                true
            ),
            new ToolProperty(
                'new_name',
                PropertyType::STRING,
                'New filename only, such as report-final.txt. Do not include directory segments.',
                true
            ),
        ];
    }

    public function __invoke(string $file_path, string $new_name): string
    {
        $filePath = trim($file_path);
        $newName = trim($new_name);

        if ($filePath === '' || $newName === '') {
            return 'Both file_path and new_name are required.';
        }

        if (!is_file($filePath)) {
            return "Source file not found: {$filePath}";
        }

        if ($newName !== basename($newName) || str_contains($newName, '/') || str_contains($newName, '\\')) {
            return 'new_name must be a filename only and cannot include directory separators.';
        }

        $targetPath = dirname($filePath) . '/' . $newName;
        if ($targetPath === $filePath) {
            return 'The file already has that name.';
        }

        if (file_exists($targetPath)) {
            return "Target file already exists: {$targetPath}";
        }

        if (!rename($filePath, $targetPath)) {
            return "Unable to rename file: {$filePath}";
        }

        return "File renamed successfully: {$targetPath}";
    }
}
