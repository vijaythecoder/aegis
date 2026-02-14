<?php

namespace App\Tools;

use App\Agent\ToolResult;

class FileListTool extends BaseTool
{
    public function name(): string
    {
        return 'file_list';
    }

    public function description(): string
    {
        return 'List directory contents from allowed paths.';
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

        if (! is_dir($path)) {
            return new ToolResult(false, null, 'Directory does not exist.');
        }

        $entries = scandir($path);
        if ($entries === false) {
            return new ToolResult(false, null, 'Failed to read directory.');
        }

        $items = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$entry;
            $items[] = is_dir($fullPath) ? $entry.'/' : $entry;
        }

        sort($items);

        return new ToolResult(true, $items);
    }
}
