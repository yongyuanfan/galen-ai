<?php

declare(strict_types=1);

namespace app\neuron\tool;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use ZipArchive;

use function dirname;
use function file_exists;
use function htmlspecialchars;
use function is_dir;
use function mkdir;
use function pathinfo;
use function preg_split;
use function strtolower;
use function trim;
use function unlink;

class GenerateWordFileTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'generate_word_file',
            'Generate a new Word file with the specified content.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'filepath',
                PropertyType::STRING,
                'Absolute or relative path where the new Word file should be created.',
                true
            ),
            new ToolProperty(
                'content',
                PropertyType::STRING,
                'The content to be written to the new Word file.',
                true
            ),
        ];
    }

    public function __invoke(string $filepath, string $content): string
    {
        $filepath = trim($filepath);
        $content = trim($content);

        if ($filepath === '') {
            return 'filepath is required.';
        }

        $targetPath = $this->normalizePath($filepath, 'docx');
        if ($targetPath === null) {
            return 'filepath must use .docx extension.';
        }

        if (file_exists($targetPath)) {
            return "Target file already exists: {$targetPath}";
        }

        $directory = dirname($targetPath);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            return "Unable to create target directory: {$directory}";
        }

        $zip = new ZipArchive();
        if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return "Unable to create file: {$targetPath}";
        }

        $files = $this->buildDocxFiles($content);
        foreach ($files as $entryPath => $entryContent) {
            if ($zip->addFromString($entryPath, $entryContent) === false) {
                $zip->close();
                unlink($targetPath);

                return "Unable to write file content: {$targetPath}";
            }
        }

        $zip->close();

        return "File created successfully: {$targetPath}";
    }

    private function normalizePath(string $filepath, string $expectedExtension): ?string
    {
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if ($extension === '') {
            return $filepath . '.' . $expectedExtension;
        }

        if ($extension !== $expectedExtension) {
            return null;
        }

        return $filepath;
    }

    /**
     * @return array<string, string>
     */
    private function buildDocxFiles(string $content): array
    {
        $paragraphsXml = '';
        foreach ($this->splitLines($content) as $line) {
            if ($line === '') {
                $paragraphsXml .= '<w:p/>';

                continue;
            }

            $paragraphsXml .= '<w:p><w:r><w:t xml:space="preserve">' . $this->escapeXml($line) . '</w:t></w:r></w:p>';
        }

        return [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '</Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:body>'
                . $paragraphsXml
                . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>'
                . '</w:body>'
                . '</w:document>',
        ];
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false || $lines === []) {
            return [''];
        }

        return $lines;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
