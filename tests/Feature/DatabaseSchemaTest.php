<?php

use App\Enums\ToolPermissionLevel;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\ToolPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has WAL journal mode configured in database config', function () {
    expect(config('database.connections.sqlite.journal_mode'))->toBe('wal');
});

it('creates all required tables', function () {
    expect(Schema::hasTable('conversations'))->toBeTrue()
        ->and(Schema::hasTable('messages'))->toBeTrue()
        ->and(Schema::hasTable('memories'))->toBeTrue()
        ->and(Schema::hasTable('settings'))->toBeTrue()
        ->and(Schema::hasTable('audit_logs'))->toBeTrue()
        ->and(Schema::hasTable('tool_permissions'))->toBeTrue();
});

it('creates audit_logs conversation foreign key column', function () {
    expect(Schema::hasColumn('audit_logs', 'conversation_id'))->toBeTrue();
});

it('creates FTS5 virtual tables', function () {
    $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table'"))->pluck('name');

    expect($tables)->toContain('messages_fts')
        ->and($tables)->toContain('memories_fts');
});

it('enforces conversation -> messages cascade delete', function () {
    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create(['conversation_id' => $conversation->id]);

    $conversation->delete();

    expect(Message::find($message->id))->toBeNull();
});

it('conversation has many messages and memories', function () {
    $conversation = Conversation::factory()->create();
    Message::factory()->count(3)->create(['conversation_id' => $conversation->id]);
    Memory::factory()->count(2)->create(['conversation_id' => $conversation->id]);

    expect($conversation->messages)->toHaveCount(3)
        ->and($conversation->memories)->toHaveCount(2);
});

it('conversation has many audit logs', function () {
    $conversation = Conversation::factory()->create();
    AuditLog::factory()->count(2)->create(['conversation_id' => $conversation->id]);

    expect($conversation->auditLogs)->toHaveCount(2);
});

it('audit log belongs to conversation', function () {
    $conversation = Conversation::factory()->create();
    $auditLog = AuditLog::factory()->create(['conversation_id' => $conversation->id]);

    expect($auditLog->conversation)->not->toBeNull()
        ->and($auditLog->conversation->is($conversation))->toBeTrue();
});

it('tool permission helpers respect expiry and allow state', function () {
    $allowed = ToolPermission::factory()->create([
        'permission' => ToolPermissionLevel::Allow,
        'expires_at' => now()->addMinutes(5),
    ]);

    $expired = ToolPermission::factory()->create([
        'permission' => ToolPermissionLevel::Allow,
        'expires_at' => now()->subMinute(),
    ]);

    $denied = ToolPermission::factory()->create([
        'permission' => ToolPermissionLevel::Deny,
        'expires_at' => null,
    ]);

    expect($allowed->isExpired())->toBeFalse()
        ->and($allowed->isAllowed())->toBeTrue()
        ->and($expired->isExpired())->toBeTrue()
        ->and($expired->isAllowed())->toBeFalse()
        ->and($denied->isExpired())->toBeFalse()
        ->and($denied->isAllowed())->toBeFalse();
});

it('supports FTS5 search on messages', function () {
    $conversation = Conversation::factory()->create();

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'content' => 'The quick brown fox jumps over the lazy dog',
    ]);

    $results = DB::select("SELECT rowid FROM messages_fts WHERE messages_fts MATCH 'quick brown'");

    expect($results)->toHaveCount(1);
});

it('supports FTS5 search on memories', function () {
    Memory::factory()->create([
        'conversation_id' => null,
        'value' => 'User prefers dark mode and Vim keybindings',
    ]);

    $results = DB::select("SELECT rowid FROM memories_fts WHERE memories_fts MATCH 'dark mode'");

    expect($results)->toHaveCount(1);
});
