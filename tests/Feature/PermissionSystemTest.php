<?php

use App\Enums\AuditLogResult;
use App\Enums\ToolPermissionLevel;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\ToolPermission;
use App\Security\AuditLogger;
use App\Security\PermissionDecision;
use App\Security\PermissionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
