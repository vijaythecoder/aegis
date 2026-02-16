<?php

namespace App\Tools\Concerns;

trait ValidatesPaths
{
    protected function validatePath(string $path): bool
    {
        $normalizedPath = $this->normalizePath($path);
        if ($normalizedPath === null) {
            return false;
        }

        $allowedPaths = (array) config('aegis.security.allowed_paths', []);

        foreach ($allowedPaths as $allowedPath) {
            $normalizedAllowed = realpath((string) $allowedPath);
            if ($normalizedAllowed === false) {
                continue;
            }

            if ($normalizedPath === $normalizedAllowed) {
                return true;
            }

            if (str_starts_with($normalizedPath, $normalizedAllowed.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $candidate = $this->isAbsolutePath($path) ? $path : base_path($path);

        $real = realpath($candidate);
        if ($real !== false) {
            return $real;
        }

        $probe = $candidate;
        $resolved = false;

        while ($resolved === false) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return null;
            }

            $probe = $parent;
            $resolved = realpath($probe);
        }

        $suffix = ltrim(substr($candidate, strlen($probe)), DIRECTORY_SEPARATOR);
        if ($suffix === '') {
            return $resolved;
        }

        $normalized = rtrim($resolved, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$suffix;

        return $normalized !== '' ? $normalized : null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\\\/', $path) === 1;
    }
}
