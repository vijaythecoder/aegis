<?php

namespace App\Tools;

use App\Tools\Concerns\ValidatesPaths;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FileWriteTool implements Tool
{
    use ValidatesPaths;

    public function name(): string
    {
        return 'file_write';
    }

    public function requiredPermission(): string
    {
        return 'write';
    }

    public function description(): Stringable|string
    {
        return 'Write file contents to allowed paths.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Absolute path to write the file to.')->required(),
            'content' => $schema->string()->description('Content to write to the file.')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $path = (string) $request->string('path');
        $content = (string) $request->string('content');

        if ($path === '') {
            return 'Error: Path is required.';
        }

        if (! $this->validatePath($path)) {
            return 'Error: Path is not allowed.';
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            return 'Error: Failed to create parent directory.';
        }

        $bytes = @file_put_contents($path, $content);
        if ($bytes === false) {
            return 'Error: Failed to write file.';
        }

        return "Wrote {$bytes} bytes to {$path}";
    }
}
