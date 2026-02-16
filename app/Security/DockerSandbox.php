<?php

namespace App\Security;

use Illuminate\Support\Facades\Process;

class DockerSandbox
{
    private const BLOCKED_PATTERNS = [
        'rm -rf /',
        'mkfs',
        'dd if=',
        ':(){ :|:& };:',
        'chmod -R 777 /',
    ];

    public function isAvailable(): bool
    {
        try {
            $result = Process::run('docker info 2>&1');

            return $result->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function resolvedMode(): string
    {
        $configured = (string) config('aegis.security.sandbox_mode', 'auto');

        if ($configured === 'none') {
            return 'none';
        }

        if ($configured === 'process') {
            return 'process';
        }

        if ($configured === 'docker') {
            return $this->isAvailable() ? 'docker' : 'process';
        }

        return $this->isAvailable() ? 'docker' : 'process';
    }

    public function execute(string $command, int $timeout = 30): SandboxResult
    {
        if ($this->isBlocked($command)) {
            return new SandboxResult(success: false, output: null, error: 'Command contains blocked patterns.');
        }

        $mode = $this->resolvedMode();

        if ($mode === 'none') {
            return new SandboxResult(success: false, output: null, error: 'Sandbox is disabled.');
        }

        return $mode === 'docker'
            ? $this->executeInDocker($command, $timeout)
            : $this->executeInProcess($command, $timeout);
    }

    private function executeInDocker(string $command, int $timeout): SandboxResult
    {
        $memoryLimit = (int) config('aegis.plugins.sandbox.memory_limit_mb', 128);
        $image = (string) config('aegis.plugins.sandbox.docker.image', 'php:8.2-cli');

        $dockerCommand = sprintf(
            'docker run --rm --network=none --memory=%dm --cpus=0.5 --pids-limit=64 --read-only %s sh -c %s',
            $memoryLimit,
            escapeshellarg($image),
            escapeshellarg($command),
        );

        try {
            $result = Process::timeout($timeout)->run($dockerCommand);
        } catch (\Throwable $e) {
            return new SandboxResult(success: false, output: null, error: $e->getMessage());
        }

        $output = trim($result->output());
        $stderr = trim($result->errorOutput());

        if (! $result->successful()) {
            return new SandboxResult(
                success: false,
                output: $output !== '' ? $output : null,
                error: $stderr !== '' ? $stderr : 'Docker execution failed with exit code '.$result->exitCode(),
            );
        }

        return new SandboxResult(success: true, output: $output, error: null);
    }

    private function executeInProcess(string $command, int $timeout): SandboxResult
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'aegis_sandbox_');
        file_put_contents($tempFile, $command);
        chmod($tempFile, 0700);

        try {
            $result = Process::timeout($timeout)->run(sprintf('bash %s', escapeshellarg($tempFile)));
        } catch (\Throwable $e) {
            @unlink($tempFile);

            return new SandboxResult(success: false, output: null, error: $e->getMessage());
        } finally {
            @unlink($tempFile);
        }

        $output = trim($result->output());
        $stderr = trim($result->errorOutput());

        if (! $result->successful()) {
            return new SandboxResult(
                success: false,
                output: $output !== '' ? $output : null,
                error: $stderr !== '' ? $stderr : 'Process execution failed with exit code '.$result->exitCode(),
            );
        }

        return new SandboxResult(success: true, output: $output, error: null);
    }

    private function isBlocked(string $command): bool
    {
        $lower = strtolower($command);

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
