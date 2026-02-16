<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ShellTool implements Tool
{
    public function name(): string
    {
        return 'shell';
    }

    public function requiredPermission(): string
    {
        return 'execute';
    }

    public function description(): Stringable|string
    {
        return 'Execute shell commands with safety checks.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()->description('The shell command to execute.')->required(),
            'timeout' => $schema->integer()->description('Timeout in seconds (default: 30).'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $command = (string) $request->string('command');
        if ($command === '') {
            return 'Error: Command is required.';
        }

        if ($this->isBlockedCommand($command)) {
            return 'Error: Command is blocked by security policy.';
        }

        $timeout = $request->integer('timeout', 30);
        if ($timeout <= 0) {
            $timeout = 30;
        }

        try {
            $result = Process::timeout($timeout)->run($command);
        } catch (ProcessTimedOutException) {
            return 'Error: Command timed out.';
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

    private function isBlockedCommand(string $command): bool
    {
        $blockedCommands = (array) config('aegis.security.blocked_commands', []);
        $normalizedCommand = strtolower($command);

        foreach ($blockedCommands as $blockedCommand) {
            if (str_contains($normalizedCommand, strtolower((string) $blockedCommand))) {
                return true;
            }
        }

        return false;
    }
}
