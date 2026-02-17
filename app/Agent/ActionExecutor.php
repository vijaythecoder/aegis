<?php

namespace App\Agent;

use App\Models\PendingAction;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

class ActionExecutor
{
    public function __construct(private readonly ToolRegistry $toolRegistry) {}

    public function execute(PendingAction $action): string
    {
        if ($action->status !== 'approved') {
            return "Cannot execute: action is {$action->status}, not approved.";
        }

        $tool = $this->toolRegistry->get($action->tool_name);

        if (! $tool instanceof Tool) {
            $action->markFailed("Tool \"{$action->tool_name}\" not found in registry.");

            return "Error: Tool \"{$action->tool_name}\" not found.";
        }

        try {
            $request = new Request($action->tool_params ?? []);
            $result = (string) $tool->handle($request);

            $action->markExecuted(Str::limit($result, 2000));

            Log::info('aegis.action.executed', [
                'action_id' => $action->id,
                'tool' => $action->tool_name,
                'result_length' => mb_strlen($result),
            ]);

            return $result;
        } catch (Throwable $e) {
            $error = Str::limit($e->getMessage(), 500);
            $action->markFailed($error);

            Log::warning('aegis.action.failed', [
                'action_id' => $action->id,
                'tool' => $action->tool_name,
                'error' => $error,
            ]);

            return "Error executing action: {$error}";
        }
    }

    public function approveAndExecute(PendingAction $action, string $via = 'chat'): string
    {
        $action->approve($via);

        return $this->execute($action);
    }

    public function expireStaleActions(): int
    {
        $expired = PendingAction::query()->expirable()->get();

        foreach ($expired as $action) {
            $action->expire();
        }

        return $expired->count();
    }
}
