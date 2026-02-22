<?php

namespace App\Console\Commands;

use App\Agent\ProjectReviewAgent;
use App\Messaging\Adapters\TelegramAdapter;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessagingChannel;
use App\Models\Project;
use App\Models\Task;
use App\Services\DeadlineReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReviewProjectsCommand extends Command
{
    protected $signature = 'aegis:projects:review';

    protected $description = 'Review active projects and generate nudge messages for projects needing attention';

    public function handle(): int
    {
        if (! config('aegis.proactive.project_review.enabled', true)) {
            $this->info('Project review is disabled.');

            return self::SUCCESS;
        }

        $projects = Project::query()
            ->active()
            ->with(['tasks', 'knowledge'])
            ->get();

        if ($projects->isEmpty()) {
            $this->info('No active projects found.');

            return self::SUCCESS;
        }

        $summaries = $projects->map(function (Project $project): array {
            $tasks = $project->tasks;
            $lastActivity = $tasks->max('updated_at') ?? $project->updated_at;

            return [
                'id' => $project->id,
                'title' => $project->title,
                'description' => $project->description,
                'deadline' => $project->deadline?->toDateTimeString(),
                'created_at' => $project->created_at->toDateTimeString(),
                'last_activity' => $lastActivity?->toDateTimeString(),
                'days_since_activity' => $lastActivity ? (int) $lastActivity->diffInDays(now()) : null,
                'task_counts' => [
                    'total' => $tasks->count(),
                    'pending' => $tasks->where('status', 'pending')->count(),
                    'in_progress' => $tasks->where('status', 'in_progress')->count(),
                    'completed' => $tasks->where('status', 'completed')->count(),
                ],
                'tasks_with_deadlines' => $tasks
                    ->whereNotNull('deadline')
                    ->where('status', '!=', 'completed')
                    ->map(fn (Task $task): array => [
                        'title' => $task->title,
                        'deadline' => $task->deadline->toDateTimeString(),
                        'hours_until_deadline' => (int) now()->diffInHours($task->deadline, false),
                        'status' => $task->status,
                    ])
                    ->values()
                    ->toArray(),
                'knowledge_keywords' => $project->knowledge
                    ->pluck('key')
                    ->toArray(),
            ];
        })->toArray();

        $prompt = "Review these active projects and generate nudges for any that need attention:\n\n"
            .json_encode($summaries, JSON_PRETTY_PRINT);

        try {
            $response = (new ProjectReviewAgent)->prompt($prompt);
            $nudges = $response['nudges'] ?? [];
        } catch (Throwable $e) {
            Log::warning('aegis.projects.review.failed', ['error' => $e->getMessage()]);
            $this->error("Project review failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($nudges === []) {
            $this->info('All projects are on track. No nudges needed.');

            return self::SUCCESS;
        }

        $this->deliverNudges($nudges);

        $this->info('Generated '.count($nudges).' nudge(s).');

        $reminderService = app(DeadlineReminderService::class);
        $remindersSent = $reminderService->sendDueReminders();

        if ($remindersSent > 0) {
            $this->info("Sent {$remindersSent} deadline reminder(s).");
        }

        $connections = $reminderService->detectCrossProjectConnections();
        foreach ($connections as $connection) {
            $keywords = implode(', ', $connection['keywords']);
            $nudgeMessage = "Projects \"{$connection['project_a']}\" and \"{$connection['project_b']}\" share common themes: {$keywords}.";

            $this->deliverNudges([[
                'project_id' => 0,
                'message' => $nudgeMessage,
                'urgency' => 'low',
            ]]);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{project_id: int, message: string, urgency: string}>  $nudges
     */
    private function deliverNudges(array $nudges): void
    {
        $conversation = Conversation::query()
            ->whereNull('agent_id')
            ->latest('id')
            ->first();

        if (! $conversation instanceof Conversation) {
            $conversation = Conversation::query()->create([
                'title' => 'Aegis',
                'last_message_at' => now(),
            ]);
        }

        $telegramEnabled = config('aegis.proactive.project_review.telegram', false);

        foreach ($nudges as $nudge) {
            $urgencyIcon = match ($nudge['urgency'] ?? 'low') {
                'high' => '🔴',
                'medium' => '🟡',
                default => '🟢',
            };

            $project = Project::query()->find($nudge['project_id']);
            $projectName = $project?->title ?? "Project #{$nudge['project_id']}";

            $content = "{$urgencyIcon} [{$projectName}] {$nudge['message']}";

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $content,
            ]);

            if ($telegramEnabled && ($nudge['urgency'] ?? '') === 'high') {
                $this->sendToTelegram($content);
            }
        }
    }

    private function sendToTelegram(string $content): void
    {
        try {
            $channel = MessagingChannel::query()
                ->where('platform', 'telegram')
                ->where('active', true)
                ->latest('updated_at')
                ->first();

            if ($channel instanceof MessagingChannel) {
                app(TelegramAdapter::class)->sendMessage($channel->platform_channel_id, $content, null);
            }
        } catch (Throwable $e) {
            Log::warning('aegis.projects.review.telegram_failed', ['error' => $e->getMessage()]);
        }
    }
}
