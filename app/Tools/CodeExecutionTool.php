<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CodeExecutionTool implements Tool
{
    private const BLOCKED_PATTERNS = [
        'rm -rf',
        'mkfs',
        'dd if=',
        ':(){ :|:& };:',
        'chmod -R 777',
        'curl.*|.*sh',
        'wget.*|.*sh',
        'exec(',
        'system(',
        'passthru(',
        'shell_exec(',
        'popen(',
        'proc_open(',
    ];

    public function name(): string
    {
        return 'code_execution';
    }

    public function requiredPermission(): string
    {
        return 'execute';
    }

    public function description(): Stringable|string
    {
        return 'Execute code snippets in a sandboxed environment. Supports PHP, bash, and Python.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'language' => $schema->string()->description('Programming language: php, bash, or python.')->required(),
            'code' => $schema->string()->description('The code to execute.')->required(),
            'timeout' => $schema->integer()->description('Timeout in seconds (default: 30, max: 60).'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $language = strtolower(trim((string) $request->string('language', 'php')));
        $code = (string) $request->string('code');
        $timeout = min($request->integer('timeout', 30), 60);

        if (trim($code) === '') {
            return 'Error: Code is required.';
        }

        if ($timeout <= 0) {
            $timeout = 30;
        }

        if ($this->containsBlockedPattern($code)) {
            return 'Error: Code contains blocked patterns for security.';
        }

        return match ($language) {
            'php' => $this->executePhp($code, $timeout),
            'bash', 'sh' => $this->executeBash($code, $timeout),
            'python', 'py' => $this->executePython($code, $timeout),
            default => "Error: Unsupported language '{$language}'. Supported: php, bash, python.",
        };
    }

    private function executePhp(string $code, int $timeout): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'aegis_');
        file_put_contents($tempFile, $code);

        try {
            $command = sprintf('php -d memory_limit=128M %s', escapeshellarg($tempFile));

            return $this->runProcess($command, $timeout);
        } finally {
            @unlink($tempFile);
        }
    }

    private function executeBash(string $code, int $timeout): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'aegis_');
        file_put_contents($tempFile, $code);
        chmod($tempFile, 0700);

        try {
            return $this->runProcess(sprintf('bash %s', escapeshellarg($tempFile)), $timeout);
        } finally {
            @unlink($tempFile);
        }
    }

    private function executePython(string $code, int $timeout): string
    {
        $pythonBin = $this->findPython();

        if ($pythonBin === null) {
            return 'Error: Python is not available on this system.';
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'aegis_');
        file_put_contents($tempFile, $code);

        try {
            return $this->runProcess(sprintf('%s %s', escapeshellarg($pythonBin), escapeshellarg($tempFile)), $timeout);
        } finally {
            @unlink($tempFile);
        }
    }

    private function runProcess(string $command, int $timeout): string
    {
        try {
            $result = Process::timeout($timeout)->run($command);
        } catch (ProcessTimedOutException) {
            return 'Error: Code execution timed out.';
        } catch (\Throwable $e) {
            return 'Error: '.$e->getMessage();
        }

        $output = $result->output();
        $stderr = $result->errorOutput();
        $exitCode = $result->exitCode();

        $response = "Exit code: {$exitCode}\n";
        if ($output !== '') {
            $response .= "stdout:\n{$output}";
        }
        if ($stderr !== '') {
            $response .= "stderr:\n{$stderr}";
        }

        return $response;
    }

    private function containsBlockedPattern(string $code): bool
    {
        $lower = strtolower($code);

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    private function findPython(): ?string
    {
        foreach (['python3', 'python'] as $bin) {
            $result = Process::quietly()->run("which {$bin}");

            if ($result->successful() && trim($result->output()) !== '') {
                return trim($result->output());
            }
        }

        return null;
    }
}
