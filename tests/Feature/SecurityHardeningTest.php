<?php

use App\Enums\MemoryType;
use App\Enums\MessageRole;
use App\Livewire\Chat;
use App\Livewire\ConversationSidebar;
use App\Memory\MemoryService;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Setting;
use App\Security\ApiKeyManager;
use App\Security\PermissionDecision;
use App\Security\PermissionManager;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class EnforcedCsrfTokenMiddleware extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

uses(RefreshDatabase::class);

it('escapes html in user messages and strips script tags from assistant markdown', function () {
    $conversation = Conversation::factory()->create();

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => "<script>alert('xss')</script> hello",
    ]);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "<script>alert('xss')</script> **safe**",
    ]);

    $html = Livewire::test(Chat::class, ['conversationId' => $conversation->id])->html();

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt; hello');
});

it('rejects post requests without csrf token when csrf middleware is active', function () {
    Route::post('/security/csrf-probe', fn () => response('ok'))
        ->middleware(['web', EnforcedCsrfTokenMiddleware::class]);

    test()->post('/security/csrf-probe', ['payload' => 'value'])
        ->assertStatus(419);
});

it('blocks path traversal attempts at permission manager boundary', function () {
    $decision = app(PermissionManager::class)->check('file_read', 'read', [
        'path' => '../../etc/passwd',
    ]);

    expect($decision)->toBe(PermissionDecision::Denied);
});

it('blocks shell injection attempts at permission manager boundary', function () {
    $decision = app(PermissionManager::class)->check('shell', 'execute', [
        'command' => 'php -v; rm -rf /',
    ]);

    expect($decision)->toBe(PermissionDecision::Denied);
});

it('stores api keys encrypted and never in plaintext database columns', function () {
    $plain = 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';
    $manager = app(ApiKeyManager::class);

    $manager->store('anthropic', $plain);

    $raw = Setting::query()
        ->where('group', 'credentials')
        ->where('key', 'anthropic_api_key')
        ->value('value');

    expect($manager->retrieve('anthropic'))->toBe($plain)
        ->and($raw)->not->toBe($plain)
        ->and($raw)->not->toContain($plain);
});

it('handles sql injection-like filter input without query breakage or data loss', function () {
    Conversation::factory()->create(['title' => 'Release planning']);
    $service = app(MemoryService::class);
    $service->store(MemoryType::Note, 'safe.note', 'Aegis keeps this row intact');

    Livewire::test(ConversationSidebar::class)
        ->set('search', "'; DROP TABLE conversations; --")
        ->assertDontSee('Release planning');

    expect(Schema::hasTable('conversations'))->toBeTrue()
        ->and(Conversation::query()->count())->toBe(1)
        ->and(Schema::hasTable('memories'))->toBeTrue()
        ->and(Memory::query()->count())->toBe(1);
});
