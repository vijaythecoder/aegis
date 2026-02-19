<?php

namespace App\Livewire;

use App\Models\Project;
use App\Models\Task;
use Livewire\Component;

class ProjectDashboard extends Component
{
    public int $projectId;

    public string $projectTitle = '';

    public string $projectDescription = '';

    public string $projectStatus = 'active';

    public string $projectCategory = '';

    public ?string $projectDeadline = null;

    public string $newTaskTitle = '';

    public string $newTaskPriority = 'medium';

    public string $flashMessage = '';

    public string $flashType = 'success';

    public string $statusFilter = '';

    public function mount(int $projectId): void
    {
        $project = Project::query()->findOrFail($projectId);

        $this->projectId = $project->id;
        $this->projectTitle = $project->title;
        $this->projectDescription = $project->description ?? '';
        $this->projectStatus = $project->status;
        $this->projectCategory = $project->category ?? '';
        $this->projectDeadline = $project->deadline?->format('Y-m-d');
    }

    public function updateProject(): void
    {
        $this->validate([
            'projectTitle' => 'required|string|max:255',
            'projectDescription' => 'nullable|string|max:2000',
            'projectStatus' => 'required|in:active,paused,completed,archived',
            'projectCategory' => 'nullable|string|max:100',
            'projectDeadline' => 'nullable|date',
        ]);

        $project = Project::query()->findOrFail($this->projectId);
        $project->update([
            'title' => $this->projectTitle,
            'description' => $this->projectDescription ?: null,
            'status' => $this->projectStatus,
            'category' => $this->projectCategory ?: null,
            'deadline' => $this->projectDeadline ?: null,
        ]);

        $this->flash('Project updated.', 'success');
    }

    public function createTask(): void
    {
        $this->validate([
            'newTaskTitle' => 'required|string|max:255',
            'newTaskPriority' => 'required|in:low,medium,high',
        ]);

        Task::query()->create([
            'project_id' => $this->projectId,
            'title' => $this->newTaskTitle,
            'priority' => $this->newTaskPriority,
            'status' => 'pending',
            'assigned_type' => 'user',
        ]);

        $this->newTaskTitle = '';
        $this->newTaskPriority = 'medium';
        $this->flash('Task added.', 'success');
    }

    public function completeTask(int $taskId): void
    {
        $task = Task::query()
            ->where('project_id', $this->projectId)
            ->findOrFail($taskId);

        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->flash("Task \"{$task->title}\" completed.", 'success');
    }

    public function updateTaskStatus(int $taskId, string $status): void
    {
        $task = Task::query()
            ->where('project_id', $this->projectId)
            ->findOrFail($taskId);

        $updates = ['status' => $status];

        if ($status === 'completed') {
            $updates['completed_at'] = now();
        }

        $task->update($updates);

        $this->flash("Task status updated to {$status}.", 'success');
    }

    public function deleteTask(int $taskId): void
    {
        $task = Task::query()
            ->where('project_id', $this->projectId)
            ->findOrFail($taskId);

        $title = $task->title;
        $task->delete();

        $this->flash("Task \"{$title}\" deleted.", 'success');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $project = Project::query()
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => function ($q): void {
                    $q->where('status', 'completed');
                },
            ])
            ->findOrFail($this->projectId);

        $tasksQuery = Task::query()->where('project_id', $this->projectId);

        if ($this->statusFilter !== '') {
            $tasksQuery->where('status', $this->statusFilter);
        }

        $tasks = $tasksQuery
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 WHEN 'completed' THEN 2 WHEN 'cancelled' THEN 3 ELSE 4 END")
            ->orderByDesc('priority')
            ->get();

        $knowledge = $project->knowledge()->get();

        $progressPercent = $project->tasks_count > 0
            ? round(($project->completed_tasks_count / $project->tasks_count) * 100)
            : 0;

        return view('livewire.project-dashboard', [
            'project' => $project,
            'tasks' => $tasks,
            'knowledge' => $knowledge,
            'progressPercent' => $progressPercent,
        ]);
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
