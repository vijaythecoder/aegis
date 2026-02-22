<?php

use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectKnowledge;
use App\Models\Task;
use App\Services\DeadlineReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(DeadlineReminderService::class);
});

// --- sendDueReminders() ---

it('sends reminder for task due within 48 hours', function () {
    $project = Project::factory()->active()->create(['title' => 'Tax Filing']);
    Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Submit return',
        'deadline' => now()->addHours(40),
        'status' => 'pending',
    ]);

    $sent = $this->service->sendDueReminders();

    expect($sent)->toBe(1);

    $message = Message::query()->first();
    expect($message->content)->toContain('Submit return')
        ->and($message->content)->toContain('⏰')
        ->and($message->content)->toContain('Tax Filing');
});

it('sends reminder for task due within 2 hours', function () {
    Task::factory()->create([
        'title' => 'Urgent task',
        'deadline' => now()->addHour(),
        'status' => 'in_progress',
    ]);

    $sent = $this->service->sendDueReminders();

    expect($sent)->toBe(1);

    $message = Message::query()->first();
    expect($message->content)->toContain('Urgent task');
});

it('tracks sent reminders in reminder_sent_at JSON', function () {
    $task = Task::factory()->create([
        'title' => 'Tracked task',
        'deadline' => now()->addHours(20),
        'status' => 'pending',
    ]);

    $this->service->sendDueReminders();

    $task->refresh();
    expect($task->reminder_sent_at)->toBeArray()
        ->and($task->reminder_sent_at)->toHaveKey('48h');
});

it('is idempotent within the same threshold level', function () {
    Task::factory()->create([
        'title' => 'No duplicate',
        'deadline' => now()->addHours(40),
        'status' => 'pending',
    ]);

    $first = $this->service->sendDueReminders();
    $second = $this->service->sendDueReminders();

    expect($first)->toBe(1)
        ->and($second)->toBe(0);
    expect(Message::query()->count())->toBe(1);
});

it('skips completed tasks', function () {
    Task::factory()->completed()->create([
        'title' => 'Done task',
        'deadline' => now()->addHours(10),
    ]);

    $sent = $this->service->sendDueReminders();

    expect($sent)->toBe(0);
    expect(Message::query()->count())->toBe(0);
});

it('skips cancelled tasks', function () {
    Task::factory()->create([
        'title' => 'Cancelled task',
        'deadline' => now()->addHours(10),
        'status' => 'cancelled',
    ]);

    $sent = $this->service->sendDueReminders();

    expect($sent)->toBe(0);
});

it('skips overdue tasks with negative hours', function () {
    Task::factory()->create([
        'title' => 'Overdue',
        'deadline' => now()->subHours(5),
        'status' => 'pending',
    ]);

    $sent = $this->service->sendDueReminders();

    expect($sent)->toBe(0);
});

it('skips tasks without deadlines', function () {
    Task::factory()->create([
        'title' => 'No deadline',
        'deadline' => null,
        'status' => 'pending',
    ]);

    $sent = $this->service->sendDueReminders();

    expect($sent)->toBe(0);
});

// --- detectCrossProjectConnections() ---

it('detects cross-project connections via shared task keywords', function () {
    $projectA = Project::factory()->active()->create(['title' => 'Fitness']);
    Task::factory()->create([
        'project_id' => $projectA->id,
        'title' => 'Morning running exercise',
    ]);
    Task::factory()->create([
        'project_id' => $projectA->id,
        'title' => 'Track nutrition daily',
    ]);

    $projectB = Project::factory()->active()->create(['title' => 'Health']);
    Task::factory()->create([
        'project_id' => $projectB->id,
        'title' => 'Schedule running sessions',
    ]);
    Task::factory()->create([
        'project_id' => $projectB->id,
        'title' => 'Plan nutrition meals',
    ]);

    $connections = $this->service->detectCrossProjectConnections();

    expect($connections)->toHaveCount(1);

    $connection = $connections->first();
    expect($connection['project_a'])->toBe('Fitness')
        ->and($connection['project_b'])->toBe('Health')
        ->and($connection['keywords'])->toContain('running')
        ->and($connection['keywords'])->toContain('nutrition');
});

it('detects connections via shared knowledge keywords', function () {
    $projectA = Project::factory()->active()->create(['title' => 'Project Alpha']);
    ProjectKnowledge::factory()->create([
        'project_id' => $projectA->id,
        'key' => 'budget.monthly',
        'value' => 'Tracking monthly budget expenses for planning',
    ]);

    $projectB = Project::factory()->active()->create(['title' => 'Project Beta']);
    ProjectKnowledge::factory()->create([
        'project_id' => $projectB->id,
        'key' => 'budget.quarterly',
        'value' => 'Review quarterly budget planning reports',
    ]);

    $connections = $this->service->detectCrossProjectConnections();

    expect($connections)->toHaveCount(1);
    expect($connections->first()['keywords'])->toContain('budget');
});

it('returns empty when fewer than 2 active projects exist', function () {
    Project::factory()->active()->create();

    $connections = $this->service->detectCrossProjectConnections();

    expect($connections)->toBeEmpty();
});

it('ignores projects with fewer than 2 shared keywords', function () {
    $projectA = Project::factory()->active()->create(['title' => 'Cooking']);
    Task::factory()->create([
        'project_id' => $projectA->id,
        'title' => 'Learn pasta recipes',
        'description' => null,
    ]);

    $projectB = Project::factory()->active()->create(['title' => 'Travel']);
    Task::factory()->create([
        'project_id' => $projectB->id,
        'title' => 'Book flights',
        'description' => null,
    ]);

    $connections = $this->service->detectCrossProjectConnections();

    expect($connections)->toBeEmpty();
});

it('limits keywords to 5 per connection', function () {
    $words = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'theta'];

    $projectA = Project::factory()->active()->create(['title' => 'Many Words A']);
    foreach ($words as $word) {
        Task::factory()->create([
            'project_id' => $projectA->id,
            'title' => "Task about {$word} research",
        ]);
    }

    $projectB = Project::factory()->active()->create(['title' => 'Many Words B']);
    foreach ($words as $word) {
        Task::factory()->create([
            'project_id' => $projectB->id,
            'title' => "Study {$word} materials",
        ]);
    }

    $connections = $this->service->detectCrossProjectConnections();

    expect($connections)->toHaveCount(1);
    expect($connections->first()['keywords'])->toHaveCount(5);
});
