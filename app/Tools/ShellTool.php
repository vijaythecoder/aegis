<?php

namespace App\Tools;

use App\Agent\ToolResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class ShellTool extends BaseTool
{
    public function name(): string
    {
        return 'shell';
    }

    public function description(): string
    {
        return 'Execute shell commands with safety checks.';
    }

    public function parameters(): array
    {
        return [
            'command' => 'string',
            'timeout' => 'int',
        ];
    }

    public function requiredPermission(): string
    {
        return 'execute';
    }

    public function execute(array $input): ToolResult
    {
        $command = (string) ($input['command'] ?? '');
        if ($command === '') {
            return new ToolResult(false, null, 'Command is required.');
        }

        if ($this->isBlockedCommand($command)) {
            return new ToolResult(false, null, 'Command is blocked by security policy.');
        }

        $timeout = (int) ($input['timeout'] ?? 30);
        if ($timeout <= 0) {
            $timeout = 30;
        }

        try {
            $result = Process::timeout($timeout)->run($command);
        } catch (ProcessTimedOutException) {
            return new ToolResult(false, null, 'Command timed out.');
        } catch (\Throwable $e) {
            return new ToolResult(false, null, $e->getMessage());
        }

        return new ToolResult(true, [
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit_code' => $result->exitCode(),
            'successful' => $result->successful(),
        ]);
    }
}
