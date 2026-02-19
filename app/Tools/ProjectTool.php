<?php

namespace App\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProjectTool implements Tool
{
    public function name(): string
    {
        return 'manage_projects';
    }

    public function requiredPermission(): string
    {
        return 'write';
    }

    public function description(): Stringable|string
    {
        return 'Create, list, update, archive, or get details of projects. '
            .'Use this when the user describes a multi-step goal, wants to organize work, '
            .'or needs to track progress on something. Examples: "I need to prepare my taxes", '
            .'"show me my projects", "the tax project is done". '
            .'Projects group related tasks and track overall progress.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['create', 'list', 'update', 'archive', 'get'])->description('The action to perform.')->required(),
            'project_id' => $schema->integer()->description('ID of the project (required for update, archive, get).'),
            'title' => $schema->string()->description('Project title (required for create, optional for update).'),
            'description' => $schema->string()->description('Project description.'),
            'category' => $schema->string()->description('Category (e.g., finance, health, education, home, work, personal).'),
            'deadline' => $schema->string()->description('Deadline in YYYY-MM-DD format.'),
            'status' => $schema->string()->enum(['active', 'paused', 'completed', 'archived'])->description('Filter for list, or new status for update.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $action = (string) $request->string('action');

        return match ($action) {
            'create' => $this->createProject($request),
            'list' => $this->listProjects($request),
            'update' => $this->updateProject($request),
            'archive' => $this->archiveProject($request),
            'get' => $this->getProject($request),
            default => "Unknown action: {$action}. Use create, list, update, archive, or get.",
        };
    }

    private function createProject(Request $request): string
    {
        $title = trim((string) $request->string('title'));

        if ($title === '') {
            return 'Project not created: title is required.';
        }

        $data = [
            'title' => $title,
            'status' => 'active',
        ];

        $description = trim((string) $request->string('description'));
        if ($description !== '') {
            $data['description'] = $description;
        }

        $category = trim((string) $request->string('category'));
        if ($category !== '') {
            $data['category'] = $category;
        }

        $deadline = trim((string) $request->string('deadline'));
        if ($deadline !== '') {
            $data['deadline'] = $deadline;
        }

        $project = Project::query()->create($data);

        $info = "Project created (ID: {$project->id}): \"{$project->title}\"";

        if ($project->category) {
            $info .= " [{$project->category}]";
        }

        if ($project->deadline) {
            $info .= " — deadline: {$project->deadline->format('M j, Y')}";
        }

        $info .= '. Status: active. You can now add tasks to this project using manage_tasks.';

        return $info;
    }

    private function listProjects(Request $request): string
    {
        $query = Project::query()->withCount([
            'tasks',
            'tasks as completed_tasks_count' => function ($q): void {
                $q->where('status', 'completed');
            },
            'tasks as pending_tasks_count' => function ($q): void {
                $q->where('status', 'pending');
            },
        ]);

        $status = trim((string) $request->string('status'));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $projects = $query->orderByDesc('updated_at')->get();

        if ($projects->isEmpty()) {
            $filter = $status !== '' ? " with status \"{$status}\"" : '';

            return "No projects found{$filter}.";
        }

        return $projects->map(function (Project $project): string {
            $deadline = $project->deadline ? ' — due: '.$project->deadline->format('M j, Y') : '';
            $category = $project->category ? " [{$project->category}]" : '';
            $progress = "{$project->completed_tasks_count}/{$project->tasks_count} tasks done";

            if ($project->pending_tasks_count > 0) {
                $progress .= " ({$project->pending_tasks_count} pending)";
            }

            return "[ID:{$project->id}] {$project->title}{$category} ({$project->status}) — {$progress}{$deadline}";
        })->implode("\n");
    }

    private function updateProject(Request $request): string
    {
        $projectId = $request->integer('project_id');

        if ($projectId === 0) {
            return 'Project not updated: project_id is required.';
        }

        $project = Project::query()->find($projectId);

        if (! $project instanceof Project) {
            return "Project not updated: no project found with ID {$projectId}.";
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

        $category = trim((string) $request->string('category'));
        if ($category !== '') {
            $updates['category'] = $category;
        }

        $deadline = trim((string) $request->string('deadline'));
        if ($deadline !== '') {
            $updates['deadline'] = $deadline;
        }

        $status = trim((string) $request->string('status'));
        if ($status !== '') {
            $updates['status'] = $status;
        }

        if ($updates === []) {
            return 'Project not updated: no changes provided.';
        }

        $project->update($updates);

        return "Project ID:{$project->id} (\"{$project->title}\") updated successfully.";
    }

    private function archiveProject(Request $request): string
    {
        $projectId = $request->integer('project_id');

        if ($projectId === 0) {
            return 'Project not archived: project_id is required.';
        }

        $project = Project::query()->find($projectId);

        if (! $project instanceof Project) {
            return "Project not archived: no project found with ID {$projectId}.";
        }

        $project->update(['status' => 'archived']);

        return "Project \"{$project->title}\" (ID:{$project->id}) archived.";
    }

    private function getProject(Request $request): string
    {
        $projectId = $request->integer('project_id');

        if ($projectId === 0) {
            return 'project_id is required for the get action.';
        }

        $project = Project::query()
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => function ($q): void {
                    $q->where('status', 'completed');
                },
            ])
            ->find($projectId);

        if (! $project instanceof Project) {
            return "No project found with ID {$projectId}.";
        }

        $lines = [
            "Project: {$project->title} (ID:{$project->id})",
            "Status: {$project->status}",
        ];

        if ($project->description) {
            $lines[] = "Description: {$project->description}";
        }

        if ($project->category) {
            $lines[] = "Category: {$project->category}";
        }

        if ($project->deadline) {
            $lines[] = 'Deadline: '.$project->deadline->format('M j, Y');
        }

        $lines[] = "Progress: {$project->completed_tasks_count}/{$project->tasks_count} tasks completed";

        $tasks = $project->tasks()->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")->get();

        if ($tasks->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Tasks:';

            foreach ($tasks as $task) {
                $statusIcon = match ($task->status) {
                    'completed' => '✅',
                    'in_progress' => '🔄',
                    default => '⬜',
                };
                $assignedTo = $task->assigned_type === 'agent' ? " (agent:{$task->assigned_id})" : '';
                $priority = $task->priority !== 'medium' ? " [{$task->priority}]" : '';
                $lines[] = "  {$statusIcon} [ID:{$task->id}] {$task->title}{$priority}{$assignedTo}";
            }
        }

        $knowledge = $project->knowledge()->limit(10)->get();

        if ($knowledge->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Knowledge:';

            foreach ($knowledge as $entry) {
                $lines[] = "  - {$entry->key}: {$entry->value}";
            }
        }

        return implode("\n", $lines);
    }
}
