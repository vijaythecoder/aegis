<?php

namespace App\Services;

use App\Models\ProjectKnowledge;
use Illuminate\Support\Collection;

class ProjectKnowledgeService
{
    public function store(int $projectId, string $key, string $value, string $type = 'note', ?int $taskId = null): ProjectKnowledge
    {
        return ProjectKnowledge::query()->create([
            'project_id' => $projectId,
            'task_id' => $taskId,
            'key' => $key,
            'value' => $value,
            'type' => $type,
        ]);
    }

    public function getForProject(int $projectId): Collection
    {
        return ProjectKnowledge::query()
            ->where('project_id', $projectId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function search(int $projectId, string $query): Collection
    {
        return ProjectKnowledge::query()
            ->where('project_id', $projectId)
            ->where(function ($q) use ($query): void {
                $q->where('key', 'like', "%{$query}%")
                    ->orWhere('value', 'like', "%{$query}%");
            })
            ->orderByDesc('created_at')
            ->get();
    }

    public function summarize(int $projectId): string
    {
        $entries = ProjectKnowledge::query()
            ->where('project_id', $projectId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($entries->isEmpty()) {
            return '';
        }

        return $entries->map(function (ProjectKnowledge $entry): string {
            $value = mb_strlen($entry->value) > 200
                ? mb_substr($entry->value, 0, 200).'...'
                : $entry->value;

            return "- {$entry->key}: {$value}";
        })->implode("\n");
    }
}
