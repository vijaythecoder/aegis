<?php

namespace App\Agent;

use Illuminate\Support\Facades\Cache;

class ProjectContextLoader
{
    private const MAX_FILE_SIZE = 50 * 1024;

    private const CACHE_KEY = 'aegis:project_context';

    private const CONTEXT_FILES = [
        '.aegis/context.md',
        '.aegis/instructions.md',
        '.cursorrules',
    ];

    public function load(): string
    {
        $projectPath = config('aegis.agent.project_path');

        if (! $projectPath || ! is_dir($projectPath)) {
            return '';
        }

        return Cache::remember(self::CACHE_KEY, 300, fn () => $this->loadFromDisk($projectPath));
    }

    private function loadFromDisk(string $projectPath): string
    {
        $sections = [];

        foreach (self::CONTEXT_FILES as $relativePath) {
            $fullPath = rtrim($projectPath, '/').'/'.$relativePath;

            if (! file_exists($fullPath) || ! is_readable($fullPath)) {
                continue;
            }

            $size = filesize($fullPath);
            if ($size > self::MAX_FILE_SIZE) {
                continue;
            }

            $content = file_get_contents($fullPath);
            if ($content !== false && trim($content) !== '') {
                $sections[] = trim($content);
            }
        }

        return implode("\n\n", $sections);
    }
}
