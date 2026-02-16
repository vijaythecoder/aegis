<?php

namespace App\Tools;

use App\Tools\Concerns\ValidatesPaths;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FileListTool implements Tool
{
    use ValidatesPaths;

    public function name(): string
    {
        return 'file_list';
    }

    public function requiredPermission(): string
    {
        return 'read';
    }

    public function description(): Stringable|string
    {
        return 'List directory contents from allowed paths.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Absolute path to the directory to list.')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $path = (string) $request->string('path');
        if ($path === '') {
            return 'Error: Path is required.';
        }

        if (! $this->validatePath($path)) {
            return 'Error: Path is not allowed.';
        }

        if (! is_dir($path)) {
            return 'Error: Directory does not exist.';
        }

        $entries = scandir($path);
        if ($entries === false) {
            return 'Error: Failed to read directory.';
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

        return implode("\n", $items);
    }
}
