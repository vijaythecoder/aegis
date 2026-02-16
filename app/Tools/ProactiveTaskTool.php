<?php

namespace App\Tools;

use App\Models\ProactiveTask;
use Cron\CronExpression;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProactiveTaskTool implements Tool
{
    public function name(): string
    {
        return 'manage_automation';
    }

    public function requiredPermission(): string
    {
        return 'write';
    }

    public function description(): Stringable|string
    {
        return 'Create, list, update, delete, or toggle automated tasks that run on a schedule. '
            .'Use this when the user wants recurring actions like morning briefings, reminders, or digests. '
            .'ALWAYS call list first to check existing tasks before creating — duplicates with the same schedule and channel are rejected. '
            .'The schedule must be a valid cron expression (e.g., "0 8 * * *" for daily at 8am, "0 8 * * 1-5" for weekdays at 8am, "0 9 * * 1" for Mondays at 9am). '
            .'Delivery channels: "chat" (in-app notification) or "telegram" (Telegram message). '
            .'For bulk delete or toggle, pass task_ids as comma-separated IDs (e.g., "6,7,8") or "all".';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['create', 'list', 'update', 'delete', 'toggle'])->description('The action to perform.')->required(),
            'name' => $schema->string()->description('Short descriptive name for the task (required for create, optional for update).'),
            'schedule' => $schema->string()->description('Cron expression for when to run. Examples: "0 8 * * *" (daily 8am), "0 8 * * 1-5" (weekdays 8am), "30 9 * * 1" (Mondays 9:30am), "0 18 * * 0" (Sundays 6pm). Times are in the user\'s local timezone.'),
            'prompt' => $schema->string()->description('The instruction for what the AI should do when the task runs (required for create).'),
            'delivery_channel' => $schema->string()->enum(['chat', 'telegram'])->description('Where to deliver the result. Default: "chat".'),
            'task_id' => $schema->integer()->description('ID of a single task to update, delete, or toggle. For bulk operations, use task_ids instead.'),
            'task_ids' => $schema->string()->description('Comma-separated list of task IDs for bulk delete or toggle (e.g., "6,7,8,9"). Use "all" to target every task.'),
            'is_active' => $schema->boolean()->description('Whether to activate the task immediately on creation. Default: true.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $action = (string) $request->string('action');

        return match ($action) {
            'create' => $this->createTask($request),
            'list' => $this->listTasks(),
            'update' => $this->updateTask($request),
            'delete' => $this->deleteTask($request),
            'toggle' => $this->toggleTask($request),
            default => "Unknown action: {$action}. Use create, list, update, delete, or toggle.",
        };
    }

    private function createTask(Request $request): string
    {
        $name = trim((string) $request->string('name'));
        $schedule = trim((string) $request->string('schedule'));
        $prompt = trim((string) $request->string('prompt'));
        $channel = (string) $request->string('delivery_channel', 'chat');
        $isActive = $request->boolean('is_active', true);

        if ($name === '') {
            return 'Task not created: name is required.';
        }

        if ($schedule === '') {
            return 'Task not created: schedule (cron expression) is required.';
        }

        if ($prompt === '') {
            return 'Task not created: prompt is required.';
        }

        if (! CronExpression::isValidExpression($schedule)) {
            return "Task not created: \"{$schedule}\" is not a valid cron expression. Use format: minute hour day month weekday (e.g., \"0 8 * * *\" for daily at 8am).";
        }

        $cron = new CronExpression($schedule);
        $nextRun = $isActive ? $cron->getNextRunDate() : null;

        $duplicates = ProactiveTask::query()
            ->where('schedule', $schedule)
            ->where('delivery_channel', $channel)
            ->where('is_active', true)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $existing = $duplicates->map(fn (ProactiveTask $t) => "ID:{$t->id} \"{$t->name}\"")->implode(', ');
            ProactiveTask::query()->whereIn('id', $duplicates->pluck('id'))->delete();

            $task = ProactiveTask::query()->create([
                'name' => $name,
                'schedule' => $schedule,
                'prompt' => $prompt,
                'delivery_channel' => $channel,
                'is_active' => $isActive,
                'next_run_at' => $nextRun,
            ]);

            $nextRunFormatted = $nextRun ? $nextRun->format('D M j, g:ia') : 'not scheduled';

            return "Replaced {$duplicates->count()} existing duplicate(s) ({$existing}) with new automation (ID: {$task->id}): \"{$name}\" — {$this->describeCron($schedule)}, via {$channel}. Next run: {$nextRunFormatted}.";
        }

        $task = ProactiveTask::query()->create([
            'name' => $name,
            'schedule' => $schedule,
            'prompt' => $prompt,
            'delivery_channel' => $channel,
            'is_active' => $isActive,
            'next_run_at' => $nextRun,
        ]);

        $nextRunFormatted = $nextRun ? $nextRun->format('D M j, g:ia') : 'not scheduled';

        return "Automation created (ID: {$task->id}): \"{$name}\" — {$this->describeCron($schedule)}, delivers via {$channel}. Next run: {$nextRunFormatted}.";
    }

    private function listTasks(): string
    {
        $tasks = ProactiveTask::query()->orderBy('id')->get();

        if ($tasks->isEmpty()) {
            return 'No automated tasks configured.';
        }

        return $tasks->map(function (ProactiveTask $task) {
            $status = $task->is_active ? 'active' : 'paused';
            $nextRun = $task->next_run_at?->format('D M j, g:ia') ?? 'not scheduled';
            $schedule = $this->describeCron($task->schedule);

            return "[ID:{$task->id}] {$task->name} ({$status}) — {$schedule}, via {$task->delivery_channel}. Next: {$nextRun}. Prompt: \"{$task->prompt}\"";
        })->implode("\n");
    }

    private function updateTask(Request $request): string
    {
        $taskId = $request->integer('task_id');

        if ($taskId === 0) {
            return 'Task not updated: task_id is required.';
        }

        $task = ProactiveTask::query()->find($taskId);

        if (! $task instanceof ProactiveTask) {
            return "Task not updated: no task found with ID {$taskId}.";
        }

        $updates = [];

        $name = trim((string) $request->string('name'));
        if ($name !== '') {
            $updates['name'] = $name;
        }

        $schedule = trim((string) $request->string('schedule'));
        if ($schedule !== '') {
            if (! CronExpression::isValidExpression($schedule)) {
                return "Task not updated: \"{$schedule}\" is not a valid cron expression.";
            }
            $updates['schedule'] = $schedule;
        }

        $prompt = trim((string) $request->string('prompt'));
        if ($prompt !== '') {
            $updates['prompt'] = $prompt;
        }

        $channel = trim((string) $request->string('delivery_channel'));
        if ($channel !== '') {
            $updates['delivery_channel'] = $channel;
        }

        if ($updates === []) {
            return 'Task not updated: no changes provided.';
        }

        if (isset($updates['schedule']) && $task->is_active) {
            $cron = new CronExpression($updates['schedule']);
            $updates['next_run_at'] = $cron->getNextRunDate();
        }

        $task->update($updates);

        return "Task ID:{$task->id} (\"{$task->name}\") updated successfully.";
    }

    private function deleteTask(Request $request): string
    {
        $ids = $this->resolveTaskIds($request);

        if ($ids === null) {
            return 'Task not deleted: provide task_id or task_ids (comma-separated IDs or "all").';
        }

        if ($ids === []) {
            return 'No tasks found matching the given IDs.';
        }

        $tasks = ProactiveTask::query()->whereIn('id', $ids)->get();

        if ($tasks->isEmpty()) {
            return 'No tasks found matching the given IDs.';
        }

        $names = $tasks->pluck('name')->implode(', ');
        $count = $tasks->count();
        ProactiveTask::query()->whereIn('id', $tasks->pluck('id'))->delete();

        return "Deleted {$count} automation(s): {$names}.";
    }

    private function toggleTask(Request $request): string
    {
        $ids = $this->resolveTaskIds($request);

        if ($ids === null) {
            return 'Task not toggled: provide task_id or task_ids (comma-separated IDs or "all").';
        }

        if ($ids === []) {
            return 'No tasks found matching the given IDs.';
        }

        $tasks = ProactiveTask::query()->whereIn('id', $ids)->get();

        if ($tasks->isEmpty()) {
            return 'No tasks found matching the given IDs.';
        }

        $results = [];

        foreach ($tasks as $task) {
            $newState = ! $task->is_active;
            $updates = ['is_active' => $newState];

            if ($newState) {
                $cron = new CronExpression($task->schedule);
                $updates['next_run_at'] = $cron->getNextRunDate();
            }

            $task->update($updates);
            $status = $newState ? 'activated' : 'paused';
            $results[] = "ID:{$task->id} \"{$task->name}\" {$status}";
        }

        return 'Toggled '.count($results)." automation(s):\n".implode("\n", $results);
    }

    /**
     * @return int[]|null
     */
    private function resolveTaskIds(Request $request): ?array
    {
        $singleId = $request->integer('task_id');

        if ($singleId > 0) {
            return [$singleId];
        }

        $bulkIds = trim((string) $request->string('task_ids'));

        if ($bulkIds === '') {
            return null;
        }

        if (strtolower($bulkIds) === 'all') {
            return ProactiveTask::query()->pluck('id')->all();
        }

        return collect(explode(',', $bulkIds))
            ->map(fn (string $id) => (int) trim($id))
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }

    private function describeCron(string $cron): string
    {
        $parts = explode(' ', trim($cron));
        if (count($parts) !== 5) {
            return $cron;
        }

        [$minute, $hour, $dom, $month, $dow] = $parts;

        $time = '';
        if ($hour !== '*' && $minute !== '*') {
            $h = (int) $hour;
            $m = (int) $minute;
            $ampm = $h >= 12 ? 'pm' : 'am';
            $h12 = $h % 12 ?: 12;
            $time = $m > 0 ? "{$h12}:{$m}{$ampm}" : "{$h12}{$ampm}";
        }

        $frequency = match (true) {
            $dow === '1-5' && $dom === '*' && $month === '*' => "weekdays at {$time}",
            $dow === '0' && $dom === '*' && $month === '*' => "Sundays at {$time}",
            $dow === '6' && $dom === '*' && $month === '*' => "Saturdays at {$time}",
            $dow === '1' && $dom === '*' && $month === '*' => "Mondays at {$time}",
            $dow === '2' && $dom === '*' && $month === '*' => "Tuesdays at {$time}",
            $dow === '3' && $dom === '*' && $month === '*' => "Wednesdays at {$time}",
            $dow === '4' && $dom === '*' && $month === '*' => "Thursdays at {$time}",
            $dow === '5' && $dom === '*' && $month === '*' => "Fridays at {$time}",
            $dow === '6,0' || $dow === '0,6' => "weekends at {$time}",
            $dow === '*' && $dom === '*' && $month === '*' && $time !== '' => "daily at {$time}",
            default => $cron,
        };

        return $frequency;
    }
}
