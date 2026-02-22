<?php

use App\Agent\ProjectReviewAgent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- ReviewProjectsCommand ---

it('exits early when project review is disabled', function () {
    config(['aegis.proactive.project_review.enabled' => false]);

    $this->artisan('aegis:projects:review')
        ->expectsOutput('Project review is disabled.')
        ->assertSuccessful();
});

it('exits early when no active projects exist', function () {
    $this->artisan('aegis:projects:review')
        ->expectsOutput('No active projects found.')
        ->assertSuccessful();
});

it('prompts ProjectReviewAgent with project summaries and delivers nudges', function () {
    ProjectReviewAgent::fake();

    $project = Project::factory()->active()->create(['title' => 'Tax 2026']);
    Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Gather documents',
        'status' => 'pending',
    ]);

    $this->artisan('aegis:projects:review')
        ->assertSuccessful();

    ProjectReviewAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Tax 2026'));
});

it('creates nudge messages in conversation with urgency icons', function () {
    ProjectReviewAgent::fake(function () {
        return [
            'nudges' => [
                ['project_id' => 0, 'message' => 'Stalled project', 'urgency' => 'high'],
                ['project_id' => 0, 'message' => 'Minor reminder', 'urgency' => 'low'],
            ],
        ];
    });

    Project::factory()->active()->create();

    $this->artisan('aegis:projects:review')
        ->expectsOutputToContain('Generated 2 nudge(s)')
        ->assertSuccessful();

    $messages = Message::query()->get();
    expect($messages)->toHaveCount(2);

    $highMsg = $messages->first(fn ($m) => str_contains($m->content, 'Stalled project'));
    expect($highMsg->content)->toContain('🔴');

    $lowMsg = $messages->first(fn ($m) => str_contains($m->content, 'Minor reminder'));
    expect($lowMsg->content)->toContain('🟢');
});

it('reports no nudges when agent returns empty array', function () {
    ProjectReviewAgent::fake(function () {
        return ['nudges' => []];
    });

    Project::factory()->active()->create();

    $this->artisan('aegis:projects:review')
        ->expectsOutput('All projects are on track. No nudges needed.')
        ->assertSuccessful();

    expect(Message::query()->count())->toBe(0);
});

it('handles ProjectReviewAgent failure gracefully', function () {
    ProjectReviewAgent::fake(function () {
        throw new RuntimeException('API timeout');
    });

    Project::factory()->active()->create();

    $this->artisan('aegis:projects:review')
        ->expectsOutputToContain('Project review failed')
        ->assertFailed();
});

it('uses existing conversation for nudge delivery', function () {
    ProjectReviewAgent::fake(function () {
        return [
            'nudges' => [['project_id' => 0, 'message' => 'Check progress', 'urgency' => 'medium']],
        ];
    });

    $conversation = Conversation::query()->create([
        'title' => 'Main Chat',
        'agent_id' => null,
        'last_message_at' => now(),
    ]);

    Project::factory()->active()->create();

    $this->artisan('aegis:projects:review')->assertSuccessful();

    $message = Message::query()->first();
    expect($message->conversation_id)->toBe($conversation->id)
        ->and($message->content)->toContain('🟡');
});

it('includes project name in nudge when project found', function () {
    $project = Project::factory()->active()->create(['title' => 'Home Renovation']);

    ProjectReviewAgent::fake(function () use ($project) {
        return [
            'nudges' => [['project_id' => $project->id, 'message' => 'No activity', 'urgency' => 'medium']],
        ];
    });

    $this->artisan('aegis:projects:review')->assertSuccessful();

    $message = Message::query()->first();
    expect($message->content)->toContain('[Home Renovation]');
});

// --- Schedule registration ---

it('registers aegis:projects:review in scheduler', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

    $reviewCommand = $events->first(fn ($event) => str_contains($event->command ?? '', 'aegis:projects:review'));

    expect($reviewCommand)->not->toBeNull();
});
