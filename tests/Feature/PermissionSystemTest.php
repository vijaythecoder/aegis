<?php

use App\Agent\AgentOrchestrator;
use App\Agent\ContextManager;
use App\Agent\Contracts\ToolInterface;
use App\Agent\SystemPromptBuilder;
use App\Agent\ToolResult;
use App\Enums\AuditLogResult;
use App\Enums\ToolPermissionLevel;
use App\Events\ApprovalRequest;
use App\Events\ApprovalResponse;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\ToolPermission;
use App\Security\AuditLogger;
use App\Security\PermissionDecision;
use App\Security\PermissionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\ToolCall;

uses(RefreshDatabase::class);

it('auto allows read operations when configured', function () {
    config()->set('aegis.security.auto_allow_read', true);

    $decision = app(PermissionManager::class)->check('file_read', 'read', [
        'path' => storage_path('app/read.txt'),
    ]);

    expect($decision)->toBe(PermissionDecision::Allowed);
});

it('requires approval for shell tools by default', function () {
    $decision = app(PermissionManager::class)->check('shell', 'execute', [
        'command' => 'php -v',
    ]);

    expect($decision)->toBe(PermissionDecision::NeedsApproval);
});

it('requires approval for write tools by default', function () {
    $decision = app(PermissionManager::class)->check('file_write', 'write', [
        'path' => storage_path('app/write.txt'),
        'content' => 'x',
    ]);

    expect($decision)->toBe(PermissionDecision::NeedsApproval);
});

it('honors persisted allow permissions when not expired', function () {
    ToolPermission::query()->create([
        'tool_name' => 'shell',
        'scope' => 'global',
        'permission' => ToolPermissionLevel::Allow,
        'expires_at' => now()->addHour(),
    ]);

    $decision = app(PermissionManager::class)->check('shell', 'execute', [
        'scope' => 'global',
        'command' => 'php -v',
    ]);

    expect($decision)->toBe(PermissionDecision::Allowed);
});

it('ignores expired permissions and asks again', function () {
    ToolPermission::query()->create([
        'tool_name' => 'shell',
        'scope' => 'global',
        'permission' => ToolPermissionLevel::Allow,
        'expires_at' => now()->subMinute(),
    ]);

    $decision = app(PermissionManager::class)->check('shell', 'execute', [
        'scope' => 'global',
        'command' => 'php -v',
    ]);

    expect($decision)->toBe(PermissionDecision::NeedsApproval);
});

it('blocks path traversal attempts', function () {
    $decision = app(PermissionManager::class)->check('file_read', 'read', [
        'path' => '../../../etc/passwd',
    ]);

    expect($decision)->toBe(PermissionDecision::Denied);
});

it('blocks dangerous shell injection operators', function (string $command) {
    $decision = app(PermissionManager::class)->check('shell', 'execute', [
        'command' => $command,
    ]);

    expect($decision)->toBe(PermissionDecision::Denied);
})->with([
    'php -v; whoami',
    'php -v && whoami',
    'php -v || whoami',
    'echo `whoami`',
    'echo $(whoami)',
]);

it('audit logger records action and result enums', function () {
    $conversation = Conversation::factory()->create();

    $entry = app(AuditLogger::class)->log(
        action: 'tool.denied',
        toolName: 'shell',
        parameters: ['command' => 'php -v; whoami'],
        result: AuditLogResult::Denied->value,
        conversationId: $conversation->id,
    );

    expect($entry)->toBeInstanceOf(AuditLog::class)
        ->and($entry->result)->toBe(AuditLogResult::Denied)
        ->and($entry->action)->toBe('tool.denied')
        ->and($entry->conversation_id)->toBe($conversation->id);
});

it('dispatches approval request and denies execution when approval denied', function () {
    Event::fake([ApprovalRequest::class]);

    $conversation = Conversation::factory()->create();
    $tool = new class implements ToolInterface
    {
        public int $executions = 0;

        public function name(): string
        {
            return 'shell';
        }

        public function description(): string
        {
            return 'shell';
        }

        public function parameters(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string'],
                ],
                'required' => ['command'],
            ];
        }

        public function requiredPermission(): string
        {
            return 'execute';
        }

        public function execute(array $input): ToolResult
        {
            $this->executions++;

            return new ToolResult(true, ['ok' => true, 'command' => $input['command'] ?? null]);
        }
    };

    Prism::fake([
        TextResponseFake::make()
            ->withToolCalls([new ToolCall('call_1', 'shell', ['command' => 'php -v'])])
            ->withFinishReason(FinishReason::ToolCalls),
        TextResponseFake::make()->withText('done'),
    ]);

    $orchestrator = new AgentOrchestrator(
        new SystemPromptBuilder([$tool]),
        new ContextManager,
        [$tool],
        null,
        app(PermissionManager::class),
        app(AuditLogger::class),
        fn (ApprovalRequest $request) => new ApprovalResponse($request->requestId, 'deny', false),
    );

    $response = $orchestrator->respond('run shell', $conversation->id);

    expect($response)->toBe('done')
        ->and($tool->executions)->toBe(0)
        ->and(AuditLog::query()->where('action', 'tool.denied')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'tool.request')->count())->toBe(1);

    Event::assertDispatched(ApprovalRequest::class);
});

it('persists always allow permission and executes tool', function () {
    Event::fake([ApprovalRequest::class]);

    $conversation = Conversation::factory()->create();
    $tool = new class implements ToolInterface
    {
        public int $executions = 0;

        public function name(): string
        {
            return 'shell';
        }

        public function description(): string
        {
            return 'shell';
        }

        public function parameters(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string'],
                ],
                'required' => ['command'],
            ];
        }

        public function requiredPermission(): string
        {
            return 'execute';
        }

        public function execute(array $input): ToolResult
        {
            $this->executions++;

            return new ToolResult(true, ['ok' => true, 'command' => $input['command'] ?? null]);
        }
    };

    Prism::fake([
        TextResponseFake::make()
            ->withToolCalls([new ToolCall('call_1', 'shell', ['command' => 'php -v'])])
            ->withFinishReason(FinishReason::ToolCalls),
        TextResponseFake::make()->withText('done'),
    ]);

    $orchestrator = new AgentOrchestrator(
        new SystemPromptBuilder([$tool]),
        new ContextManager,
        [$tool],
        null,
        app(PermissionManager::class),
        app(AuditLogger::class),
        fn (ApprovalRequest $request) => new ApprovalResponse($request->requestId, 'allow', true),
    );

    $response = $orchestrator->respond('run shell', $conversation->id);

    expect($response)->toBe('done')
        ->and($tool->executions)->toBe(1)
        ->and(ToolPermission::query()->where('tool_name', 'shell')->where('permission', ToolPermissionLevel::Allow)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'tool.executed')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'tool.allowed')->count())->toBe(1);

    Event::assertDispatched(ApprovalRequest::class);
});
