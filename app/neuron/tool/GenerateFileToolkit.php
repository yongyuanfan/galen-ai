<?php

declare(strict_types=1);

namespace app\neuron\tool;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

class GenerateFileToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "This toolkit allows you to generate office suite files, such as Word documents, Excel files, and PowerPoint presentations.";
    }

    public function provide(): array
    {
        return [
            GenerateWordFileTool::make(),
            GenerateExcelFileTool::make(),
            GeneratePPTFileTool::make(),
        ];
    }
}
