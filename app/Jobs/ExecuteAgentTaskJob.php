<?php

namespace App\Jobs;

use App\Agent\AgentRegistry;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProjectKnowledge;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ExecuteAgentTaskJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 30;

    public function __construct(
        private readonly int $taskId,
    ) {}

    public function handle(AgentRegistry $agentRegistry): void
    {
        $task = Task::query()->with('project')->find($this->taskId);

        if (! $task instanceof Task) {
            Log::warning('aegis.task.execute.not_found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->assigned_type !== 'agent' || $task->assigned_id === null) {
            Log::warning('aegis.task.execute.not_assigned_to_agent', ['task_id' => $this->taskId]);

            return;
        }

        $maxDepth = (int) config('aegis.delegation.max_depth', 3);
        if ($task->delegation_depth > $maxDepth) {
            Log::warning('aegis.task.execute.depth_exceeded', [
                'task_id' => $task->id,
                'delegation_depth' => $task->delegation_depth,
                'max_depth' => $maxDepth,
            ]);

            $task->update([
                'status' => 'cancelled',
                'output' => "Task cancelled: delegation depth limit ({$maxDepth}) exceeded. Depth: {$task->delegation_depth}.",
            ]);

            return;
        }

        $task->update(['status' => 'in_progress']);

        // Bind current task context so nested tools (e.g. TaskTool) can detect delegation
        app()->instance('aegis.current_task_id', $task->id);

        try {
            $agent = $agentRegistry->resolve($task->assigned_id);

            $conversation = Conversation::query()->create([
                'agent_id' => $task->assigned_id,
                'title' => "Task: {$task->title}",
            ]);

            $prompt = $this->buildPrompt($task);

            $response = $agent->forConversation($conversation->id)->prompt($prompt);

            $responseText = (string) $response;

            $task->update([
                'status' => 'completed',
                'output' => $responseText,
                'completed_at' => now(),
            ]);

            if ($task->project_id !== null) {
                ProjectKnowledge::query()->create([
                    'project_id' => $task->project_id,
                    'task_id' => $task->id,
                    'key' => $task->title,
                    'value' => $responseText,
                    'type' => 'artifact',
                ]);
            }

            $this->notifyDelegator($task, $responseText);

            Log::debug('aegis.task.execute.completed', [
                'task_id' => $task->id,
                'agent_id' => $task->assigned_id,
            ]);
        } catch (Throwable $e) {
            Log::warning('aegis.task.execute.failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            $task->update(['status' => 'pending']);

            throw $e;
        }
    }

    private function notifyDelegator(Task $task, string $responseText): void
    {
        if ($task->delegated_from === null) {
            return;
        }

        $sourceTask = Task::query()->find($task->delegated_from);

        if (! $sourceTask instanceof Task) {
            return;
        }

        // Find the conversation where the source task was being worked on
        $conversation = null;

        if ($sourceTask->assigned_type === 'agent' && $sourceTask->assigned_id !== null) {
            $conversation = Conversation::query()
                ->where('agent_id', $sourceTask->assigned_id)
                ->latest('id')
                ->first();
        }

        if (! $conversation instanceof Conversation) {
            return;
        }

        $agentName = Agent::query()->find($task->assigned_id)?->name ?? 'Agent';
        $summary = Str::limit($responseText, 500);

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::System,
            'content' => "\u{2705} {$agentName} completed delegated task \"{$task->title}\": {$summary}",
        ]);

        Log::debug('aegis.delegation.notified', [
            'delegated_task_id' => $task->id,
            'source_task_id' => $sourceTask->id,
            'conversation_id' => $conversation->id,
        ]);
    }

    private function buildPrompt(Task $task): string
    {
        $lines = [
            'You have been assigned a task:',
            '',
            "Title: {$task->title}",
        ];

        if ($task->description) {
            $lines[] = "Description: {$task->description}";
        }

        if ($task->project) {
            $lines[] = '';
            $lines[] = "Project context: {$task->project->title}";

            if ($task->project->description) {
                $lines[] = $task->project->description;
            }
        }

        $lines[] = '';
        $lines[] = 'Complete this task and provide your output.';

        return implode("\n", $lines);
    }
}
