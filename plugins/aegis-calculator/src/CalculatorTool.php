<?php

namespace AegisCalculator;

use App\Agent\ToolResult;
use App\Tools\BaseTool;

class CalculatorTool extends BaseTool
{
    public function name(): string
    {
        return 'calculator';
    }

    public function description(): string
    {
        return 'Evaluate mathematical expressions';
    }

    public function requiredPermission(): string
    {
        return 'read';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['expression'],
            'properties' => [
                'expression' => [
                    'type' => 'string',
                    'description' => 'Math expression to evaluate',
                ],
            ],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $expression = trim((string) ($input['expression'] ?? ''));

        if ($expression === '' || preg_match('/^[\d\s\+\-\*\/\.\(\)]+$/', $expression) !== 1) {
            return new ToolResult(false, null, 'Invalid expression: only numbers and basic operators allowed');
        }

        try {
            $result = eval("return {$expression};");

            return new ToolResult(true, $result);
        } catch (\Throwable $exception) {
            return new ToolResult(false, null, 'Evaluation error: '.$exception->getMessage());
        }
    }
}
