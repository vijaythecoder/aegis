<?php

namespace App\Tools;

use App\Agent\ActionExecutor;
use App\Models\PendingAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProposeActionTool implements Tool
{
    public function name(): string
    {
        return 'propose_action';
    }

    public function requiredPermission(): string
    {
        return 'write';
    }

    public function description(): Stringable|string
    {
        return 'Propose an action that requires user approval before execution. '
            .'Use this for sensitive operations: running shell commands, writing/deleting files, sending messages on behalf of the user, '
            .'making API calls, modifying automations, or any action with real-world consequences. '
            .'The user will see a summary and can approve or reject. Approved actions execute automatically. '
            .'Use "approve" when the user says "yes", "do it", "go ahead", "approved", or similar affirmation for a pending action. '
            .'Use "reject" when the user declines. Use "list" to show pending actions. Use "cancel" to withdraw your own proposal.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['propose', 'approve', 'reject', 'list', 'cancel'])->description('The action to perform.')->required(),
            'tool_name' => $schema->string()->description('Name of the tool to execute when approved (e.g., "shell", "file_write", "web_search"). Required for propose.'),
            'tool_params' => $schema->object()->description('Parameters to pass to the tool when approved. Must be a valid JSON object matching the tool\'s schema.'),
            'description' => $schema->string()->description('Clear, human-readable description of what this action will do. Required for propose.'),
            'reason' => $schema->string()->description('Why you are proposing this action — what triggered it and what benefit it provides.'),
            'expires_in_hours' => $schema->integer()->description('Hours until this proposal expires (default: 24). Use shorter times for urgent actions.'),
            'action_id' => $schema->integer()->description('ID of the pending action to cancel.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $action = (string) $request->string('action');

        return match ($action) {
            'propose' => $this->propose($request),
            'approve' => $this->approveAction($request),
            'reject' => $this->rejectAction($request),
            'list' => $this->listPending(),
            'cancel' => $this->cancel($request),
            default => "Unknown action: {$action}. Use propose, approve, reject, list, or cancel.",
        };
    }

    private function propose(Request $request): string
    {
        $toolName = trim((string) $request->string('tool_name'));
        $description = trim((string) $request->string('description'));

        if ($toolName === '') {
            return 'Proposal rejected: tool_name is required.';
        }

        if ($description === '') {
            return 'Proposal rejected: description is required.';
        }

        $toolParams = $request['tool_params'] ?? [];
        $params = is_array($toolParams) ? $toolParams : [];

        $expiresInHours = $request->integer('expires_in_hours', 24);
        if ($expiresInHours <= 0) {
            $expiresInHours = 24;
        }

        $pendingAction = PendingAction::query()->create([
            'tool_name' => $toolName,
            'tool_params' => $params,
            'description' => $description,
            'reason' => trim((string) $request->string('reason')) ?: null,
            'status' => 'pending',
            'delivery_channel' => 'chat',
            'expires_at' => now()->addHours($expiresInHours),
        ]);

        $expiresFormatted = $pendingAction->expires_at->format('M j, g:ia');

        return "✋ Action proposed (ID: {$pendingAction->id}): {$description}\n"
            ."Tool: {$toolName}\n"
            ."Expires: {$expiresFormatted}\n"
            .'The user can approve or reject this action. Approved actions execute automatically.';
    }

    private function approveAction(Request $request): string
    {
        $action = $this->resolvePendingAction($request);

        if (is_string($action)) {
            return $action;
        }

        $executor = app(ActionExecutor::class);
        $result = $executor->approveAndExecute($action, 'chat');

        return "✅ Action approved and executed (ID: {$action->id}): {$action->description}\n\nResult:\n{$result}";
    }

    private function rejectAction(Request $request): string
    {
        $action = $this->resolvePendingAction($request);

        if (is_string($action)) {
            return $action;
        }

        $action->reject('chat');

        return "❌ Action rejected (ID: {$action->id}): {$action->description}";
    }

    private function resolvePendingAction(Request $request): PendingAction|string
    {
        $actionId = $request->integer('action_id');

        if ($actionId > 0) {
            $action = PendingAction::query()->find($actionId);

            if (! $action instanceof PendingAction) {
                return "No action found with ID {$actionId}.";
            }

            if (! $action->isPending()) {
                return "Action ID:{$actionId} is already {$action->status}.";
            }

            if ($action->isExpired()) {
                $action->expire();

                return "Action ID:{$actionId} has expired.";
            }

            return $action;
        }

        $latest = PendingAction::query()
            ->pending()
            ->latest()
            ->first();

        if (! $latest instanceof PendingAction) {
            return 'No pending actions to approve or reject.';
        }

        if ($latest->isExpired()) {
            $latest->expire();

            return "The most recent action (ID:{$latest->id}) has expired.";
        }

        return $latest;
    }

    private function listPending(): string
    {
        $actions = PendingAction::query()
            ->pending()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        if ($actions->isEmpty()) {
            return 'No pending actions awaiting approval.';
        }

        return "Pending actions:\n".$actions->map(function (PendingAction $action) {
            $expires = $action->expires_at?->format('M j, g:ia') ?? 'never';
            $status = $action->isExpired() ? '⏰ expired' : '⏳ pending';

            return "[ID:{$action->id}] {$status} — {$action->description} (tool: {$action->tool_name}, expires: {$expires})";
        })->implode("\n");
    }

    private function cancel(Request $request): string
    {
        $actionId = $request->integer('action_id');

        if ($actionId === 0) {
            return 'Cancel requires action_id.';
        }

        $action = PendingAction::query()->find($actionId);

        if (! $action instanceof PendingAction) {
            return "No action found with ID {$actionId}.";
        }

        if (! $action->isPending()) {
            return "Action ID:{$actionId} is already {$action->status}.";
        }

        $action->reject('agent');

        return "Action ID:{$actionId} cancelled: {$action->description}";
    }
}
