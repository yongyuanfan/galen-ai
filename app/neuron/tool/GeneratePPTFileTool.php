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

        if ($filepath === '') {
            return 'filepath is required.';
        }

        $targetPath = $this->normalizePath($filepath, 'pptx');
        if ($targetPath === null) {
            return 'filepath must use .pptx extension.';
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

        $files = $this->buildPptxFiles($content);
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
    private function buildPptxFiles(string $content): array
    {
        $paragraphsXml = '';
        foreach ($this->splitLines($content) as $line) {
            if ($line === '') {
                $paragraphsXml .= '<a:p><a:endParaRPr lang="en-US"/></a:p>';

                continue;
            }

            $paragraphsXml .= '<a:p><a:r><a:rPr lang="en-US" sz="2800"/><a:t>' . $this->escapeXml($line) . '</a:t></a:r><a:endParaRPr lang="en-US"/></a:p>';
        }

        return [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
                . '<Override PartName="/ppt/slides/slide1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
                . '</Relationships>',
            'ppt/presentation.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
                . '<p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst>'
                . '<p:sldSz cx="9144000" cy="6858000" type="screen4x3"/>'
                . '<p:notesSz cx="6858000" cy="9144000"/>'
                . '</p:presentation>',
            'ppt/_rels/presentation.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>'
                . '</Relationships>',
            'ppt/slides/slide1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
                . '<p:cSld><p:spTree>'
                . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
                . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
                . '<p:sp>'
                . '<p:nvSpPr><p:cNvPr id="2" name="TextBox 1"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
                . '<p:spPr><a:xfrm><a:off x="685800" y="685800"/><a:ext cx="7772400" cy="5486400"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
                . '<p:txBody><a:bodyPr wrap="square"/><a:lstStyle/>'
                . $paragraphsXml
                . '</p:txBody>'
                . '</p:sp>'
                . '</p:spTree></p:cSld>'
                . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
                . '</p:sld>',
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
