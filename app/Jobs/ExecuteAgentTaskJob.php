<?php

namespace App\Jobs;

use App\Agent\AgentRegistry;
use App\Models\Conversation;
use App\Models\ProjectKnowledge;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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

        $task->update(['status' => 'in_progress']);

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
