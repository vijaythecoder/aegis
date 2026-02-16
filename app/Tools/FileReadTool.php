<?php

namespace App\Tools;

use App\Tools\Concerns\ValidatesPaths;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FileReadTool implements Tool
{
    use ValidatesPaths;

    public function name(): string
    {
        return 'file_read';
    }

    public function requiredPermission(): string
    {
        return 'read';
    }

    public function description(): Stringable|string
    {
        return 'Read file contents from allowed paths.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Absolute path to the file to read.')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $path = (string) ($request->string('path'));
        if ($path === '') {
            return 'Error: Path is required.';
        }

        if (! $this->validatePath($path)) {
            return 'Error: Path is not allowed.';
        }

        if (! is_file($path)) {
            return 'Error: File does not exist.';
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return 'Error: Failed to read file.';
        }

        return $content;
    }
}
