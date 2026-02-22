<?php

namespace App\Tools;

use App\Enums\MessageRole;
use App\Jobs\ExecuteAgentTaskJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class TaskTool implements Tool
{
    public function name(): string
    {
        return 'manage_tasks';
    }

    public function requiredPermission(): string
    {
        return 'write';
    }

    public function description(): Stringable|string
    {
        return 'Create, list, update, complete, or assign tasks. Tasks can belong to a project or be standalone. '
            .'Use this when the user needs to track action items, to-dos, or deliverables. '
            .'Tasks can be assigned to "user" (human-tracked) or to a specific agent (for AI execution). '
            .'Examples: "add a task to gather W-2s for my tax project", "mark the research task as done", '
            .'"show me my pending tasks", "assign this to FitCoach".';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['create', 'list', 'update', 'complete', 'assign'])->description('The action to perform.')->required(),
            'task_id' => $schema->integer()->description('ID of the task (required for update, complete, assign).'),
            'project_id' => $schema->integer()->description('Project ID to link the task to (optional for create, filter for list).'),
            'title' => $schema->string()->description('Task title (required for create, optional for update).'),
            'description' => $schema->string()->description('Task description or details.'),
            'assigned_type' => $schema->string()->enum(['user', 'agent'])->description('Who handles this task: "user" (human) or "agent" (AI agent). Default: user.'),
            'assigned_id' => $schema->string()->description('Agent slug when assigned_type is "agent" (e.g., "fitcoach").'),
            'priority' => $schema->string()->enum(['low', 'medium', 'high'])->description('Task priority. Default: medium.'),
            'deadline' => $schema->string()->description('Deadline in YYYY-MM-DD format.'),
            'status' => $schema->string()->enum(['pending', 'in_progress', 'completed', 'cancelled'])->description('Filter for list, or new status for update.'),
            'output' => $schema->string()->description('Deliverable or result text when completing a task.'),
            'source_task_id' => $schema->integer()->description('ID of the originating task when delegating work to another agent. Used for delegation tracking.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $action = (string) $request->string('action');

        return match ($action) {
            'create' => $this->createTask($request),
            'list' => $this->listTasks($request),
            'update' => $this->updateTask($request),
            'complete' => $this->completeTask($request),
            'assign' => $this->assignTask($request),
            default => "Unknown action: {$action}. Use create, list, update, complete, or assign.",
        };
    }

    private function createTask(Request $request): string
    {
        $title = trim((string) $request->string('title'));

        if ($title === '') {
            return 'Task not created: title is required.';
        }

        $data = [
            'title' => $title,
            'status' => 'pending',
            'priority' => (string) $request->string('priority', 'medium'),
            'assigned_type' => 'user',
        ];

        $projectId = $request->integer('project_id');
        if ($projectId > 0) {
            $project = Project::query()->find($projectId);
            if (! $project instanceof Project) {
                return "Task not created: no project found with ID {$projectId}.";
            }
            $data['project_id'] = $projectId;
        }

        $description = trim((string) $request->string('description'));
        if ($description !== '') {
            $data['description'] = $description;
        }

        $deadline = trim((string) $request->string('deadline'));
        if ($deadline !== '') {
            $data['deadline'] = $deadline;
        }

        $assignedType = trim((string) $request->string('assigned_type'));
        if ($assignedType === 'agent') {
            $slug = trim((string) $request->string('assigned_id'));
            if ($slug === '') {
                return 'Task not created: assigned_id (agent slug) is required when assigned_type is "agent".';
            }

            $agent = Agent::query()->where('slug', $slug)->where('is_active', true)->first();
            if (! $agent instanceof Agent) {
                return "Task not created: no active agent found with slug \"{$slug}\".";
            }

            $data['assigned_type'] = 'agent';
            $data['assigned_id'] = $agent->id;
        }

        $sourceTaskId = $request->integer('source_task_id');
        if ($sourceTaskId > 0 && $data['assigned_type'] === 'agent') {
            $sourceTask = Task::query()->find($sourceTaskId);
            if ($sourceTask instanceof Task) {
                $delegationResult = $this->validateDelegation($sourceTask, (int) $data['assigned_id']);
                if (is_string($delegationResult)) {
                    return $delegationResult;
                }

                $data['delegated_from'] = $sourceTask->id;
                $data['delegation_depth'] = $sourceTask->delegation_depth + 1;
            }
        }

        if (! isset($data['delegated_from']) && $data['assigned_type'] === 'agent') {
            $contextTaskId = app()->bound('aegis.current_task_id') ? app('aegis.current_task_id') : null;
            if ($contextTaskId !== null) {
                $contextTask = Task::query()->find($contextTaskId);
                if ($contextTask instanceof Task) {
                    $delegationResult = $this->validateDelegation($contextTask, (int) $data['assigned_id']);
                    if (is_string($delegationResult)) {
                        return $delegationResult;
                    }

                    $data['delegated_from'] = $contextTask->id;
                    $data['delegation_depth'] = $contextTask->delegation_depth + 1;
                }
            }
        }

        $task = Task::query()->create($data);

        $info = "Task created (ID: {$task->id}): \"{$task->title}\"";

        if ($projectId > 0) {
            $info .= " in project ID:{$projectId}";
        }

        if ($task->assigned_type === 'agent') {
            $agentName = Agent::query()->find($task->assigned_id)?->name ?? 'unknown';
            $info .= " — assigned to {$agentName}";

            // Auto-dispatch delegation tasks
            if ($task->delegated_from !== null || $task->priority === 'high') {
                ExecuteAgentTaskJob::dispatch($task->id);
                $info .= ' (dispatched for background execution)';
            } else {
                $agent = Agent::query()->find($task->assigned_id);
                if ($agent instanceof Agent) {
                    $this->insertCollaborativeMessage($task, $agent);
                }
            }
        }

        $info .= " [{$task->priority}] status: {$task->status}.";

        if ($task->delegated_from !== null) {
            $info .= " Delegation depth: {$task->delegation_depth}.";
        }

        return $info;
    }

    private function validateDelegation(Task $sourceTask, int $targetAgentId): ?string
    {
        $maxDepth = (int) config('aegis.delegation.max_depth', 3);
        $newDepth = $sourceTask->delegation_depth + 1;

        if ($newDepth > $maxDepth) {
            Log::warning('aegis.delegation.depth_exceeded', [
                'source_task_id' => $sourceTask->id,
                'current_depth' => $sourceTask->delegation_depth,
                'max_depth' => $maxDepth,
            ]);

            return "Task not created: delegation depth limit ({$maxDepth}) exceeded. Current chain depth: {$sourceTask->delegation_depth}.";
        }

        if (config('aegis.delegation.circular_check', true) && $sourceTask->hasAgentInDelegationChain($targetAgentId)) {
            Log::warning('aegis.delegation.circular_detected', [
                'source_task_id' => $sourceTask->id,
                'target_agent_id' => $targetAgentId,
            ]);

            return 'Task not created: circular delegation detected — target agent already appears in the delegation chain.';
        }

        if ($newDepth > 2) {
            Log::info('aegis.delegation.deep_chain', [
                'source_task_id' => $sourceTask->id,
                'new_depth' => $newDepth,
            ]);
        }

        return null;
    }

    private function listTasks(Request $request): string
    {
        $query = Task::query();

        $projectId = $request->integer('project_id');
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $status = trim((string) $request->string('status'));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $assignedType = trim((string) $request->string('assigned_type'));
        if ($assignedType !== '') {
            $query->where('assigned_type', $assignedType);
        }

        $tasks = $query->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 WHEN 'completed' THEN 2 WHEN 'cancelled' THEN 3 ELSE 4 END")
            ->orderByDesc('priority')
            ->limit(50)
            ->get();

        if ($tasks->isEmpty()) {
            return 'No tasks found matching the given filters.';
        }

        return $tasks->map(function (Task $task): string {
            $statusIcon = match ($task->status) {
                'completed' => '✅',
                'in_progress' => '🔄',
                'cancelled' => '❌',
                default => '⬜',
            };

            $project = $task->project_id ? " (project:{$task->project_id})" : '';
            $priority = $task->priority !== 'medium' ? " [{$task->priority}]" : '';
            $deadline = $task->deadline ? ' due: '.$task->deadline->format('M j') : '';

            $assignedTo = '';
            if ($task->assigned_type === 'agent') {
                $agentName = Agent::query()->find($task->assigned_id)?->name ?? "agent:{$task->assigned_id}";
                $assignedTo = " → {$agentName}";
            }

            return "{$statusIcon} [ID:{$task->id}] {$task->title}{$priority}{$project}{$assignedTo}{$deadline}";
        })->implode("\n");
    }

    private function updateTask(Request $request): string
    {
        $taskId = $request->integer('task_id');

        if ($taskId === 0) {
            return 'Task not updated: task_id is required.';
        }

        $task = Task::query()->find($taskId);

        if (! $task instanceof Task) {
            return "Task not updated: no task found with ID {$taskId}.";
        }

        $updates = [];

        $title = trim((string) $request->string('title'));
        if ($title !== '') {
            $updates['title'] = $title;
        }

        $description = trim((string) $request->string('description'));
        if ($description !== '') {
            $updates['description'] = $description;
        }

        $priority = trim((string) $request->string('priority'));
        if ($priority !== '') {
            $updates['priority'] = $priority;
        }

        $deadline = trim((string) $request->string('deadline'));
        if ($deadline !== '') {
            $updates['deadline'] = $deadline;
        }

        $status = trim((string) $request->string('status'));
        if ($status !== '') {
            $updates['status'] = $status;

            if ($status === 'completed') {
                $updates['completed_at'] = now();
            }
        }

        if ($updates === []) {
            return 'Task not updated: no changes provided.';
        }

        $task->update($updates);

        return "Task ID:{$task->id} (\"{$task->title}\") updated successfully.";
    }

    private function completeTask(Request $request): string
    {
        $taskId = $request->integer('task_id');

        if ($taskId === 0) {
            return 'Task not completed: task_id is required.';
        }

        $task = Task::query()->find($taskId);

        if (! $task instanceof Task) {
            return "Task not completed: no task found with ID {$taskId}.";
        }

        $updates = [
            'status' => 'completed',
            'completed_at' => now(),
        ];

        $output = trim((string) $request->string('output'));
        if ($output !== '') {
            $updates['output'] = $output;
        }

        $task->update($updates);

        $info = "Task \"{$task->title}\" (ID:{$task->id}) marked as completed.";

        if ($output !== '') {
            $info .= ' Output recorded.';
        }

        return $info;
    }

    private function assignTask(Request $request): string
    {
        $taskId = $request->integer('task_id');

        if ($taskId === 0) {
            return 'Task not assigned: task_id is required.';
        }

        $task = Task::query()->find($taskId);

        if (! $task instanceof Task) {
            return "Task not assigned: no task found with ID {$taskId}.";
        }

        $assignedType = trim((string) $request->string('assigned_type', 'user'));

        if ($assignedType === 'agent') {
            $slug = trim((string) $request->string('assigned_id'));
            if ($slug === '') {
                return 'Task not assigned: assigned_id (agent slug) is required when assigning to an agent.';
            }

            $agent = Agent::query()->where('slug', $slug)->where('is_active', true)->first();
            if (! $agent instanceof Agent) {
                return "Task not assigned: no active agent found with slug \"{$slug}\".";
            }

            $task->update([
                'assigned_type' => 'agent',
                'assigned_id' => $agent->id,
            ]);

            if ($task->priority === 'high') {
                ExecuteAgentTaskJob::dispatch($task->id);

                return "Task \"{$task->title}\" (ID:{$task->id}) assigned to {$agent->name} for background execution.";
            }

            $this->insertCollaborativeMessage($task, $agent);

            return "Task \"{$task->title}\" (ID:{$task->id}) assigned to {$agent->name} for collaborative work.";
        }

        $task->update([
            'assigned_type' => 'user',
            'assigned_id' => null,
        ]);

        return "Task \"{$task->title}\" (ID:{$task->id}) assigned to you.";
    }

    private function insertCollaborativeMessage(Task $task, Agent $agent): void
    {
        $conversation = Conversation::query()
            ->where('agent_id', $agent->id)
            ->latest('id')
            ->first();

        if (! $conversation instanceof Conversation) {
            $conversation = Conversation::query()->create([
                'agent_id' => $agent->id,
                'title' => $agent->name,
            ]);
        }

        $description = $task->description ? "\n{$task->description}" : '';
        $project = $task->project_id
            ? "\nProject: ".($task->project?->title ?? "ID:{$task->project_id}")
            : '';

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::System,
            'content' => "New Task: {$task->title}{$description}{$project}\n\nReply to work on this task. When done, it can be marked complete.",
        ]);
    }
}
