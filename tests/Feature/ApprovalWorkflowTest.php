<?php

use App\Agent\ActionExecutor;
use App\Models\PendingAction;
use App\Tools\ProposeActionTool;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tool = app(ProposeActionTool::class);
});

it('proposes an action and creates pending record', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'propose',
        'tool_name' => 'shell',
        'tool_params' => ['command' => 'echo hello'],
        'description' => 'Run a simple echo command',
        'reason' => 'Testing the approval system',
    ]));

    expect($result)->toContain('Action proposed')
        ->and($result)->toContain('echo command');

    $action = PendingAction::query()->first();
    expect($action)->not->toBeNull()
        ->and($action->tool_name)->toBe('shell')
        ->and($action->tool_params)->toBe(['command' => 'echo hello'])
        ->and($action->status)->toBe('pending')
        ->and($action->expires_at)->not->toBeNull();
});

it('requires tool_name for proposal', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'propose',
        'description' => 'Missing tool name',
    ]));

    expect($result)->toContain('tool_name is required');
});

it('requires description for proposal', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'propose',
        'tool_name' => 'shell',
    ]));

    expect($result)->toContain('description is required');
});

it('approves and executes an action by ID', function () {
    $action = PendingAction::factory()->create([
        'tool_name' => 'shell',
        'tool_params' => ['command' => 'echo approved'],
        'description' => 'Echo test command',
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'approve',
        'action_id' => $action->id,
    ]));

    expect($result)->toContain('approved and executed')
        ->and($result)->toContain('approved');

    $action->refresh();
    expect($action->status)->toBe('executed')
        ->and($action->resolved_via)->toBe('chat')
        ->and($action->result)->toContain('approved');
});

it('approves the latest pending action when no ID given', function () {
    PendingAction::factory()->create([
        'description' => 'Older action',
        'created_at' => now()->subMinute(),
    ]);

    $latest = PendingAction::factory()->create([
        'description' => 'Latest action',
        'tool_name' => 'shell',
        'tool_params' => ['command' => 'echo latest'],
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'approve',
    ]));

    expect($result)->toContain('Latest action');

    $latest->refresh();
    expect($latest->status)->toBe('executed');
});

it('rejects an action by ID', function () {
    $action = PendingAction::factory()->create([
        'description' => 'Rejected action',
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'reject',
        'action_id' => $action->id,
    ]));

    expect($result)->toContain('rejected');

    $action->refresh();
    expect($action->status)->toBe('rejected')
        ->and($action->resolved_via)->toBe('chat');
});

it('cannot approve already resolved action', function () {
    $action = PendingAction::factory()->approved()->create();

    $result = (string) $this->tool->handle(new Request([
        'action' => 'approve',
        'action_id' => $action->id,
    ]));

    expect($result)->toContain('already approved');
});

it('handles expired action on approval attempt', function () {
    $action = PendingAction::factory()->expired()->create();

    $result = (string) $this->tool->handle(new Request([
        'action' => 'approve',
        'action_id' => $action->id,
    ]));

    expect($result)->toContain('expired');

    $action->refresh();
    expect($action->status)->toBe('expired');
});

it('lists pending actions', function () {
    PendingAction::factory()->create(['description' => 'Action A']);
    PendingAction::factory()->create(['description' => 'Action B']);
    PendingAction::factory()->approved()->create(['description' => 'Resolved']);

    $result = (string) $this->tool->handle(new Request(['action' => 'list']));

    expect($result)->toContain('Action A')
        ->and($result)->toContain('Action B')
        ->and($result)->not->toContain('Resolved');
});

it('shows empty list when no pending actions', function () {
    $result = (string) $this->tool->handle(new Request(['action' => 'list']));

    expect($result)->toContain('No pending actions');
});

it('cancels a pending action', function () {
    $action = PendingAction::factory()->create([
        'description' => 'Cancellable action',
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'cancel',
        'action_id' => $action->id,
    ]));

    expect($result)->toContain('cancelled');

    $action->refresh();
    expect($action->status)->toBe('rejected')
        ->and($action->resolved_via)->toBe('agent');
});

it('handles failed tool execution gracefully', function () {
    $action = PendingAction::factory()->create([
        'tool_name' => 'nonexistent_tool',
        'tool_params' => [],
        'description' => 'Will fail because tool not found',
    ]);

    $action->approve('chat');

    $executor = app(ActionExecutor::class);
    $result = $executor->execute($action);

    expect($result)->toContain('not found');

    $action->refresh();
    expect($action->status)->toBe('failed');
});

it('expires stale actions automatically', function () {
    PendingAction::factory()->create([
        'expires_at' => now()->subHour(),
        'description' => 'Stale action',
    ]);

    PendingAction::factory()->create([
        'expires_at' => now()->addHour(),
        'description' => 'Fresh action',
    ]);

    $executor = app(ActionExecutor::class);
    $count = $executor->expireStaleActions();

    expect($count)->toBe(1);
    expect(PendingAction::query()->where('status', 'expired')->count())->toBe(1)
        ->and(PendingAction::query()->pending()->count())->toBe(1);
});

it('model detects expired status correctly', function () {
    $expired = PendingAction::factory()->expired()->create();
    $fresh = PendingAction::factory()->create(['expires_at' => now()->addDay()]);
    $noExpiry = PendingAction::factory()->create(['expires_at' => null]);

    expect($expired->isExpired())->toBeTrue()
        ->and($fresh->isExpired())->toBeFalse()
        ->and($noExpiry->isExpired())->toBeFalse();
});

it('factory creates valid records for all states', function () {
    $pending = PendingAction::factory()->create();
    $approved = PendingAction::factory()->approved()->create();
    $rejected = PendingAction::factory()->rejected()->create();

    expect($pending->status)->toBe('pending')
        ->and($approved->status)->toBe('approved')
        ->and($rejected->status)->toBe('rejected');
});

it('is auto-discovered by the tool registry', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->get('propose_action'))->toBeInstanceOf(ProposeActionTool::class);
});

it('sets custom expiry time', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'propose',
        'tool_name' => 'shell',
        'description' => 'Quick action',
        'expires_in_hours' => 2,
    ]));

    $action = PendingAction::query()->first();
    $hoursUntilExpiry = now()->diffInHours($action->expires_at);

    expect($hoursUntilExpiry)->toBeLessThanOrEqual(2)
        ->and($hoursUntilExpiry)->toBeGreaterThanOrEqual(1);
});

it('returns no pending on approve when none exist', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'approve',
    ]));

    expect($result)->toContain('No pending actions');
});
