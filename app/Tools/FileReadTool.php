<?php

namespace App\Tools;

use App\Agent\ToolResult;

class FileReadTool extends BaseTool
{
    public function name(): string
    {
        return 'file_read';
    }

    public function description(): string
    {
        return 'Read file contents from allowed paths.';
    }

    public function parameters(): array
    {
        return [
            'path' => 'string',
        ];
    }

    public function requiredPermission(): string
    {
        return 'read';
    }

    public function execute(array $input): ToolResult
    {
        $path = (string) ($input['path'] ?? '');
        if ($path === '') {
            return new ToolResult(false, null, 'Path is required.');
        }

        if (! $this->validatePath($path)) {
            return new ToolResult(false, null, 'Path is not allowed.');
        }

        if (! is_file($path)) {
            return new ToolResult(false, null, 'File does not exist.');
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return new ToolResult(false, null, 'Failed to read file.');
        }

        return new ToolResult(true, $content);
    }
}
