<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DeadlineReminderService
{
    /** @var array<string, int> */
    private const THRESHOLDS = [
        '48h' => 48,
        '24h' => 24,
        '2h' => 2,
    ];

    public function sendDueReminders(): int
    {
        $tasks = Task::query()
            ->whereNotNull('deadline')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with('project')
            ->get();

        $sent = 0;

        foreach ($tasks as $task) {
            $hoursUntilDeadline = (int) now()->diffInHours($task->deadline, false);

            if ($hoursUntilDeadline < 0) {
                continue;
            }

            foreach (self::THRESHOLDS as $label => $threshold) {
                if ($hoursUntilDeadline <= $threshold && ! $this->wasReminderSent($task, $label)) {
                    $this->sendReminder($task, $label, $hoursUntilDeadline);
                    $this->markReminderSent($task, $label);
                    $sent++;

                    break;
                }
            }
        }

        return $sent;
    }

    /**
     * @return Collection<int, array{project_a: string, project_b: string, keywords: array<int, string>}>
     */
    public function detectCrossProjectConnections(): Collection
    {
        $projects = \App\Models\Project::query()
            ->active()
            ->with(['tasks', 'knowledge'])
            ->get();

        if ($projects->count() < 2) {
            return new Collection;
        }

        $projectKeywords = $projects->mapWithKeys(function ($project) {
            $words = collect();

            foreach ($project->tasks as $task) {
                $words = $words->merge($this->extractKeywords($task->title.' '.($task->description ?? '')));
            }

            foreach ($project->knowledge as $knowledge) {
                $words = $words->merge($this->extractKeywords($knowledge->key.' '.$knowledge->value));
            }

            return [$project->id => ['title' => $project->title, 'keywords' => $words->unique()->values()]];
        });

        $connections = new Collection;
        $projectIds = $projectKeywords->keys()->toArray();

        for ($i = 0; $i < count($projectIds) - 1; $i++) {
            for ($j = $i + 1; $j < count($projectIds); $j++) {
                $idA = $projectIds[$i];
                $idB = $projectIds[$j];

                $overlap = $projectKeywords[$idA]['keywords']
                    ->intersect($projectKeywords[$idB]['keywords'])
                    ->values();

                if ($overlap->count() >= 2) {
                    $connections->push([
                        'project_a' => $projectKeywords[$idA]['title'],
                        'project_b' => $projectKeywords[$idB]['title'],
                        'keywords' => $overlap->take(5)->toArray(),
                    ]);
                }
            }
        }

        return $connections;
    }

    private function wasReminderSent(Task $task, string $label): bool
    {
        $sentAt = $task->reminder_sent_at ?? [];

        return isset($sentAt[$label]);
    }

    private function markReminderSent(Task $task, string $label): void
    {
        $sentAt = $task->reminder_sent_at ?? [];
        $sentAt[$label] = now()->toDateTimeString();

        $task->update(['reminder_sent_at' => $sentAt]);
    }

    private function sendReminder(Task $task, string $label, int $hoursRemaining): void
    {
        $projectName = $task->project?->title;
        $projectContext = $projectName ? " in project \"{$projectName}\"" : '';

        $content = "⏰ Reminder: Task \"{$task->title}\"{$projectContext} is due in {$hoursRemaining} hours.";

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

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $content,
        ]);

        Log::debug('aegis.deadline.reminder_sent', [
            'task_id' => $task->id,
            'label' => $label,
            'hours_remaining' => $hoursRemaining,
        ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function extractKeywords(string $text): Collection
    {
        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'this', 'that', 'my', 'your', 'it', 'its', 'has', 'have', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'can', 'be', 'been', 'being', 'not', 'no', 'all', 'each', 'every', 'any', 'some'];

        $words = collect(preg_split('/[\s\-_.,;:!?()]+/', mb_strtolower($text)))
            ->filter(fn (string $word): bool => mb_strlen($word) >= 3)
            ->reject(fn (string $word): bool => in_array($word, $stopWords, true));

        return $words->values();
    }
}
