<?php

namespace App\Tools;

use App\Agent\ToolResult;

class FileWriteTool extends BaseTool
{
    public function name(): string
    {
        return 'file_write';
    }

    public function description(): string
    {
        return 'Write file contents to allowed paths.';
    }

    public function parameters(): array
    {
        return [
            'path' => 'string',
            'content' => 'string',
        ];
    }

    public function requiredPermission(): string
    {
        return 'write';
    }

    public function execute(array $input): ToolResult
    {
        $path = (string) ($input['path'] ?? '');
        $content = (string) ($input['content'] ?? '');

        if ($path === '') {
            return new ToolResult(false, null, 'Path is required.');
        }

        if (! $this->validatePath($path)) {
            return new ToolResult(false, null, 'Path is not allowed.');
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            return new ToolResult(false, null, 'Failed to create parent directory.');
        }

        $bytes = @file_put_contents($path, $content);
        if ($bytes === false) {
            return new ToolResult(false, null, 'Failed to write file.');
        }

        return new ToolResult(true, [
            'path' => $path,
            'bytes' => $bytes,
        ]);
    }
}
