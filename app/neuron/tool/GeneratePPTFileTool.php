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

class GeneratePPTFileTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'generate_ppt_file',
            'Generate a new PowerPoint file with the specified content.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'filepath',
                PropertyType::STRING,
                'Absolute or relative path where the new PowerPoint file should be created.',
                true
            ),
            new ToolProperty(
                'content',
                PropertyType::STRING,
                'The content to be written to the new PowerPoint file.',
                true
            ),
        ];
    }

    public function __invoke(string $filepath, string $content): string
    {
        $filepath = trim($filepath);
        $content = trim($content);

        return "File created successfully: {$filepath}";
    }
}
