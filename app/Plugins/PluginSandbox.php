<?php

namespace App\Plugins;

use App\Agent\Contracts\ToolInterface;
use App\Agent\ToolResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class PluginSandbox
{
    private const PERMISSION_ALIASES = [
        'read' => 'filesystem',
        'write' => 'filesystem',
        'file' => 'filesystem',
        'files' => 'filesystem',
        'fs' => 'filesystem',
        'path' => 'filesystem',
        'paths' => 'filesystem',
        'directory' => 'filesystem',
        'directories' => 'filesystem',
        'exec' => 'shell',
        'execute' => 'shell',
        'process' => 'shell',
        'http' => 'network',
        'https' => 'network',
        'net' => 'network',
    ];

    public function execute(ToolInterface $tool, array $input, PluginManifest $manifest): ToolResult
    {
        $config = SandboxConfig::fromConfig();
        $this->ensureTempDirectory($config->tempPath());

        $blockedPath = $this->firstBlockedPath($input, $manifest, $config);

        if ($blockedPath !== null) {
            Log::warning('aegis.plugin.sandbox.path_denied', [
                'plugin' => $manifest->name,
                'tool' => $tool->name(),
                'path' => $blockedPath,
            ]);

            return new ToolResult(false, null, 'Path is outside sandbox paths.');
        }

        $missingPermissions = $this->missingPermissions($tool, $input, $manifest);

        if ($missingPermissions !== []) {
            Log::warning('aegis.plugin.sandbox.permission_denied', [
                'plugin' => $manifest->name,
                'tool' => $tool->name(),
                'missing_permissions' => $missingPermissions,
            ]);

            return new ToolResult(
                false,
                null,
                'Plugin does not declare required permissions: '.implode(', ', $missingPermissions),
            );
        }

        if ($this->resolvedMode($config) === 'docker') {
            Log::warning('aegis.plugin.sandbox.mode_fallback', [
                'plugin' => $manifest->name,
                'tool' => $tool->name(),
                'mode' => 'docker',
                'fallback' => 'process',
            ]);
        }

        return $this->executeInProcess($tool, $input, $manifest, $config);
    }

    private function executeInProcess(ToolInterface $tool, array $input, PluginManifest $manifest, SandboxConfig $config): ToolResult
    {
        try {
            $encodedPayload = base64_encode((string) json_encode([
                'tool_class' => $tool::class,
                'input' => $input,
                'plugin_path' => $manifest->path,
                'autoload' => $manifest->autoload,
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            return new ToolResult(false, null, $exception->getMessage());
        }

        $command = [
            PHP_BINARY,
            '-d',
            'memory_limit='.$config->memoryLimitMb().'M',
            '-r',
            $this->runnerScript(),
            base_path('vendor/autoload.php'),
            base_path('bootstrap/app.php'),
            $encodedPayload,
            $manifest->path,
            $config->tempPath(),
        ];

        $process = new Process(
            command: $command,
            cwd: base_path(),
            env: [
                'AEGIS_PLUGIN_SANDBOX' => '1',
                'TMPDIR' => $config->tempPath(),
            ],
        );
        $process->setTimeout($config->timeoutSeconds());

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ToolResult(false, null, 'Plugin execution timed out.');
        } catch (Throwable $exception) {
            return new ToolResult(false, null, $exception->getMessage());
        }

        $stdout = trim($process->getOutput());

        if ($stdout === '') {
            $error = trim($process->getErrorOutput());

            if ($error === '') {
                $error = 'Plugin sandbox produced no output.';
            }

            return new ToolResult(false, null, $error);
        }

        $decoded = json_decode($stdout, true);

        if (! is_array($decoded)) {
            $error = trim($process->getErrorOutput());

            return new ToolResult(
                false,
                null,
                $error !== '' ? $error : 'Plugin sandbox returned an invalid response.',
            );
        }

        return new ToolResult(
            success: (bool) ($decoded['success'] ?? false),
            output: $decoded['output'] ?? null,
            error: is_string($decoded['error'] ?? null) ? $decoded['error'] : null,
        );
    }

    private function missingPermissions(ToolInterface $tool, array $input, PluginManifest $manifest): array
    {
        $allowedPermissions = $this->normalizePermissions($manifest->permissions);
        $requestedPermissions = $this->requestedPermissions($tool, $input);

        if ($requestedPermissions === []) {
            return [];
        }

        return array_values(array_diff($requestedPermissions, $allowedPermissions));
    }

    private function requestedPermissions(ToolInterface $tool, array $input): array
    {
        $requested = [
            ...$this->detectPermissionsFromInput($input),
            ...$this->detectPermissionsFromSource($tool),
        ];

        if (strtolower($tool->requiredPermission()) === 'execute') {
            $requested[] = 'shell';
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $permission): ?string => $this->normalizePermission($permission),
            $requested,
        ))));
    }

    private function detectPermissionsFromSource(ToolInterface $tool): array
    {
        try {
            $reflection = new ReflectionClass($tool);
            $file = $reflection->getFileName();
        } catch (Throwable) {
            return [];
        }

        if (! is_string($file) || ! is_file($file)) {
            return [];
        }

        $source = (string) file_get_contents($file);

        if ($source === '') {
            return [];
        }

        $detected = [];

        if (
            preg_match('/\b(shell_exec|exec|passthru|proc_open|popen|pcntl_exec)\s*\(/i', $source) === 1
            || str_contains($source, 'Symfony\\Component\\Process\\Process')
        ) {
            $detected[] = 'shell';
        }

        if (
            preg_match('/\b(curl_init|curl_exec|fsockopen|stream_socket_client)\s*\(/i', $source) === 1
            || preg_match('/\bHttp::(get|post|put|patch|delete|timeout|with)\b/i', $source) === 1
            || preg_match('/https?:\/\//i', $source) === 1
        ) {
            $detected[] = 'network';
        }

        return $detected;
    }

    private function detectPermissionsFromInput(array $input): array
    {
        $detected = [];
        $this->walkInput($input, '', function (string $key, mixed $value) use (&$detected): void {
            $normalizedKey = strtolower($key);

            if ($normalizedKey !== '' && (str_contains($normalizedKey, 'path') || str_contains($normalizedKey, 'file') || str_contains($normalizedKey, 'dir'))) {
                $detected[] = 'filesystem';
            }

            if ($normalizedKey !== '' && (str_contains($normalizedKey, 'command') || str_contains($normalizedKey, 'shell'))) {
                $detected[] = 'shell';
            }

            if (
                $normalizedKey !== ''
                && (str_contains($normalizedKey, 'url') || str_contains($normalizedKey, 'uri') || str_contains($normalizedKey, 'endpoint') || str_contains($normalizedKey, 'host'))
            ) {
                $detected[] = 'network';
            }

            if (is_string($value) && preg_match('/^https?:\/\//i', trim($value)) === 1) {
                $detected[] = 'network';
            }
        });

        return array_values(array_unique($detected));
    }

    private function firstBlockedPath(array $input, PluginManifest $manifest, SandboxConfig $config): ?string
    {
        $allowedRoots = array_values(array_filter([
            realpath($manifest->path) ?: null,
            realpath($config->tempPath()) ?: null,
        ]));

        if ($allowedRoots === []) {
            return null;
        }

        foreach ($this->extractPathCandidates($input) as $path) {
            $normalizedPath = $this->normalizePath($path, $manifest->path);

            if ($normalizedPath === null) {
                return $path;
            }

            $isAllowed = false;

            foreach ($allowedRoots as $allowedRoot) {
                if ($normalizedPath === $allowedRoot || str_starts_with($normalizedPath, $allowedRoot.DIRECTORY_SEPARATOR)) {
                    $isAllowed = true;
                    break;
                }
            }

            if (! $isAllowed) {
                return $path;
            }
        }

        return null;
    }

    private function extractPathCandidates(array $input): array
    {
        $paths = [];

        $this->walkInput($input, '', function (string $key, mixed $value) use (&$paths): void {
            if (! is_string($value)) {
                return;
            }

            $normalizedKey = strtolower($key);

            if (
                $normalizedKey !== ''
                && (str_contains($normalizedKey, 'path') || str_contains($normalizedKey, 'file') || str_contains($normalizedKey, 'dir'))
            ) {
                $paths[] = $value;
            }
        });

        return array_values(array_unique($paths));
    }

    private function walkInput(array $input, string $parentKey, callable $visitor): void
    {
        foreach ($input as $key => $value) {
            $currentKey = is_string($key) ? $key : $parentKey;

            $visitor($currentKey, $value);

            if (is_array($value)) {
                $this->walkInput($value, $currentKey, $visitor);
            }
        }
    }

    private function normalizePermissions(array $permissions): array
    {
        $normalized = [];

        foreach ($permissions as $permission) {
            if (! is_string($permission)) {
                continue;
            }

            $mapped = $this->normalizePermission($permission);

            if ($mapped === null) {
                continue;
            }

            $normalized[] = $mapped;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizePermission(string $permission): ?string
    {
        $normalized = strtolower(trim($permission));

        if ($normalized === '' || $normalized === 'none') {
            return null;
        }

        return self::PERMISSION_ALIASES[$normalized] ?? $normalized;
    }

    private function resolvedMode(SandboxConfig $config): string
    {
        if ($config->mode() !== 'docker') {
            return 'process';
        }

        $finder = new ExecutableFinder;

        return $finder->find('docker') === null ? 'process' : 'docker';
    }

    private function ensureTempDirectory(string $tempPath): void
    {
        if (is_dir($tempPath)) {
            return;
        }

        File::makeDirectory($tempPath, 0755, true);
    }

    private function normalizePath(string $path, string $pluginPath): ?string
    {
        $trimmedPath = trim($path);

        if ($trimmedPath === '') {
            return null;
        }

        $candidate = $this->isAbsolutePath($trimmedPath)
            ? $trimmedPath
            : rtrim($pluginPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$trimmedPath;

        $resolved = realpath($candidate);

        if ($resolved !== false) {
            return $resolved;
        }

        $probe = $candidate;

        while (true) {
            $parent = dirname($probe);

            if ($parent === $probe) {
                return null;
            }

            $resolvedParent = realpath($parent);

            if ($resolvedParent !== false) {
                $suffix = ltrim(substr($candidate, strlen($parent)), DIRECTORY_SEPARATOR);

                return $suffix === ''
                    ? $resolvedParent
                    : rtrim($resolvedParent, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$suffix;
            }

            $probe = $parent;
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\\\/', $path) === 1;
    }

    private function runnerScript(): string
    {
        return <<<'PHP'
$autoloadPath = (string) ($argv[1] ?? '');
$appPath = (string) ($argv[2] ?? '');
$encodedPayload = (string) ($argv[3] ?? '');
$pluginPath = (string) ($argv[4] ?? '');
$tempPath = (string) ($argv[5] ?? '');

$emit = static function (bool $success, mixed $output = null, ?string $error = null): void {
    $payload = [
        'success' => $success,
        'output' => $output,
        'error' => $error,
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (! is_string($encoded)) {
        $encoded = '{"success":false,"output":null,"error":"Failed to encode sandbox response."}';
    }

    fwrite(STDOUT, $encoded);
};

try {
    if ($autoloadPath === '' || $appPath === '') {
        $emit(false, null, 'Sandbox bootstrap paths are missing.');

        return;
    }

    require $autoloadPath;

    $app = require $appPath;
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $decodedPayload = base64_decode($encodedPayload, true);
    $payload = is_string($decodedPayload) ? json_decode($decodedPayload, true) : null;

    if (! is_array($payload)) {
        $emit(false, null, 'Invalid sandbox payload.');

        return;
    }

    foreach ((array) (($payload['autoload'] ?? [])['psr-4'] ?? []) as $namespace => $relativePath) {
        spl_autoload_register(static function (string $class) use ($namespace, $relativePath, $pluginPath): void {
            if (! str_starts_with($class, (string) $namespace)) {
                return;
            }

            $relativeClass = substr($class, strlen((string) $namespace));
            $file = rtrim($pluginPath, DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR
                .trim((string) $relativePath, '/\\')
                .DIRECTORY_SEPARATOR
                .str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)
                .'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    $toolClass = (string) ($payload['tool_class'] ?? '');

    if ($toolClass === '' || ! class_exists($toolClass)) {
        $emit(false, null, 'Sandbox tool class is unavailable.');

        return;
    }

    $tool = app()->make($toolClass);

    $result = $tool->execute((array) ($payload['input'] ?? []));

    $emit(
        (bool) ($result->success ?? false),
        $result->output ?? null,
        is_string($result->error ?? null) ? $result->error : null,
    );
} catch (Throwable $exception) {
    $emit(false, null, $exception->getMessage());
}
PHP;
    }
}
