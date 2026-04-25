<?php

declare(strict_types=1);

namespace app\neuron\tool;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use ZipArchive;

use function chr;
use function dirname;
use function explode;
use function file_exists;
use function htmlspecialchars;
use function intdiv;
use function is_dir;
use function mkdir;
use function pathinfo;
use function preg_split;
use function strtolower;
use function trim;
use function unlink;

class GenerateExcelFileTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'generate_excel_file',
            'Generate a new Excel file with the specified content.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'filepath',
                PropertyType::STRING,
                'Absolute or relative path where the new Excel file should be created.',
                true
            ),
            new ToolProperty(
                'content',
                PropertyType::STRING,
                'The content to be written to the new Excel file.',
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

        $targetPath = $this->normalizePath($filepath, 'xlsx');
        if ($targetPath === null) {
            return 'filepath must use .xlsx extension.';
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

        $files = $this->buildXlsxFiles($content);
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
    private function buildXlsxFiles(string $content): array
    {
        $rows = $this->splitRows($content);
        $sheetRowsXml = '';
        $maxColumnCount = 1;

        foreach ($rows as $rowIndex => $cells) {
            $rowNumber = $rowIndex + 1;
            if ($cells !== []) {
                $cellXml = '';
                $maxColumnCount = max($maxColumnCount, count($cells));
                foreach ($cells as $columnIndex => $cellValue) {
                    $columnName = $this->columnName($columnIndex + 1);
                    $cellReference = $columnName . $rowNumber;
                    $cellXml .= '<c r="' . $cellReference . '" t="inlineStr"><is><t xml:space="preserve">' . $this->escapeXml($cellValue) . '</t></is></c>';
                }
                $sheetRowsXml .= '<row r="' . $rowNumber . '">' . $cellXml . '</row>';

                continue;
            }

            $sheetRowsXml .= '<row r="' . $rowNumber . '"/>';
        }

        $dimensionRef = 'A1:' . $this->columnName($maxColumnCount) . count($rows);

        return [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '</Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<dimension ref="' . $dimensionRef . '"/>'
                . '<sheetData>' . $sheetRowsXml . '</sheetData>'
                . '</worksheet>',
        ];
    }

    /**
     * @return list<list<string>>
     */
    private function splitRows(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false || $lines === []) {
            return [['']];
        }

        $rows = [];
        foreach ($lines as $line) {
            $rows[] = explode("\t", $line);
        }

        return $rows;
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
