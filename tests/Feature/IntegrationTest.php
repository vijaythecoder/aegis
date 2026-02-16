<?php

use App\Agent\AegisAgent;
use App\Agent\Contracts\ToolInterface;
use App\Agent\SystemPromptBuilder;
use App\Agent\ToolResult;
use App\Enums\MemoryType;
use App\Enums\MessageRole;
use App\Memory\ConversationService;
use App\Memory\FactExtractor;
use App\Memory\MemoryService;
use App\Memory\MessageService;
use App\Models\Conversation;
use App\Models\Message;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('extracts memories from conversation facts and recalls preferences in system prompt', function () {
    $conversation = Conversation::factory()->create();
    $extractor = app(FactExtractor::class);
    $memoryService = app(MemoryService::class);

    $facts = $extractor->extract('My name is Alice. I prefer dark mode. I use neovim daily.', $conversation);
    $memoryService->store(MemoryType::Preference, 'global.editor_theme', 'solarized dark', null, 0.7);

    $registry = new ToolRegistry(sys_get_temp_dir().'/nonexistent_tools_'.uniqid());
    $prompt = (new SystemPromptBuilder($registry))->build($conversation);

    expect($facts)->toHaveCount(3)
        ->and($prompt)->toContain('User preferences:')
        ->and($prompt)->toContain('user.preference.dark_mode: dark mode')
        ->and($prompt)->toContain('global.editor_theme: solarized dark');
});

it('manages conversation lifecycle from creation to archive and cascade delete', function () {
    $conversationService = app(ConversationService::class);
    $messageService = app(MessageService::class);

    $conversation = $conversationService->create('First lifecycle message', 'claude-sonnet-4', 'anthropic');
    $messageService->store($conversation->id, MessageRole::User, 'First lifecycle message');
    $messageService->store($conversation->id, MessageRole::Assistant, 'Lifecycle response');

    $conversationService->archive($conversation->id);

    $messageIds = Message::query()
        ->where('conversation_id', $conversation->id)
        ->pluck('id')
        ->all();

    expect($conversation->fresh()->is_archived)->toBeTrue()
        ->and($conversation->fresh()->title)->toBe('First lifecycle message')
        ->and($messageService->loadHistory($conversation->id))->toHaveCount(2)
        ->and($messageService->tokenCount($conversation->id))->toBeGreaterThan(0);

    $conversationService->delete($conversation->id);

    expect(Conversation::query()->find($conversation->id))->toBeNull()
        ->and(Message::query()->whereIn('id', $messageIds)->count())->toBe(0);
});

it('builds system prompt with tool catalog and defaults when no preferences exist', function () {
    $tool = new class implements ToolInterface
    {
        public function name(): string
        {
            return 'inspect_tool';
        }

        public function description(): string
        {
            return 'Inspects project files';
        }

        public function parameters(): array
        {
            return [
                'type' => 'object',
                'properties' => [],
            ];
        }

        public function requiredPermission(): string
        {
            return 'read';
        }

        public function execute(array $input): ToolResult
        {
            return new ToolResult(true, $input);
        }
    };

    $registry = new ToolRegistry(sys_get_temp_dir().'/nonexistent_tools_'.uniqid());
    $registry->register($tool);
    $prompt = (new SystemPromptBuilder($registry))->build();

    expect($prompt)->toContain('You are Aegis, AI under your Aegis.')
        ->and($prompt)->toContain('Available tools:')
        ->and($prompt)->toContain('- inspect_tool: Inspects project files')
        ->and($prompt)->toContain('User preferences:')
        ->and($prompt)->toContain('- none');
});

it('reads provider model and timeout from aegis agent configuration', function () {
    config()->set('aegis.agent.default_provider', 'openai');
    config()->set('aegis.agent.default_model', 'gpt-4o-mini');
    config()->set('aegis.agent.timeout', 45);

    $agent = app(AegisAgent::class);

    expect($agent->provider())->toBe('openai')
        ->and($agent->model())->toBe('gpt-4o-mini')
        ->and($agent->timeout())->toBe(45);
});
