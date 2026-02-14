<?php

use App\Agent\AegisAgent;
use App\Agent\AgentOrchestrator;
use App\Agent\ContextManager;
use App\Agent\Contracts\ToolInterface;
use App\Agent\SystemPromptBuilder;
use App\Agent\ToolResult;
use App\Enums\MemoryType;
use App\Enums\MessageRole;
use App\Enums\ToolPermissionLevel;
use App\Events\ApprovalRequest;
use App\Events\ApprovalResponse;
use App\Livewire\ConversationSidebar;
use App\Memory\ConversationService;
use App\Memory\FactExtractor;
use App\Memory\MemoryService;
use App\Memory\MessageService;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ToolPermission;
use App\Security\AuditLogger;
use App\Security\PermissionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;

uses(RefreshDatabase::class);

it('runs full chat flow and persists conversation state across tool loops', function () {
    $conversation = app(ConversationService::class)->create('Integration flow');

    $tool = new class implements ToolInterface
    {
        public function name(): string
        {
            return 'echo_tool';
        }

        public function description(): string
        {
            return 'Echoes provided value';
        }

        public function parameters(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'string', 'description' => 'Value to echo'],
                ],
                'required' => ['value'],
            ];
        }

        public function requiredPermission(): string
        {
            return 'read';
        }

        public function execute(array $input): ToolResult
        {
            return new ToolResult(true, 'echo: '.($input['value'] ?? ''));
        }
    };

    Prism::fake([
        TextResponseFake::make()->withText('First assistant response'),
    ]);

    $orchestrator = new AgentOrchestrator(
        new SystemPromptBuilder([$tool]),
        new ContextManager,
        [$tool],
        null,
        app(PermissionManager::class),
        app(AuditLogger::class),
    );

    $firstResponse = $orchestrator->respond('Hello there', $conversation->id);
    $firstLastMessageAt = $conversation->fresh()->last_message_at;

    Prism::fake([
        TextResponseFake::make()
            ->withToolCalls([new ToolCall('call_1', 'echo_tool', ['value' => 'tool-value'])])
            ->withFinishReason(FinishReason::ToolCalls),
        TextResponseFake::make()->withText('Tool flow complete'),
    ]);

    $secondResponse = $orchestrator->respond('Use the tool now', $conversation->id);
    $toolMessage = Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Tool)
        ->first();

    expect($firstResponse)->toBe('First assistant response')
        ->and($secondResponse)->toBe('Tool flow complete')
        ->and(Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::User)->count())->toBe(2)
        ->and(Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::Assistant)->count())->toBe(2)
        ->and(Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::Tool)->count())->toBe(1)
        ->and($toolMessage?->tool_name)->toBe('echo_tool')
        ->and($toolMessage?->tool_result['success'] ?? null)->toBeTrue()
        ->and($toolMessage?->content)->toBe('echo: tool-value')
        ->and($conversation->fresh()->last_message_at)->not->toBeNull()
        ->and($conversation->fresh()->last_message_at?->greaterThanOrEqualTo($firstLastMessageAt))->toBeTrue()
        ->and(AuditLog::query()->where('conversation_id', $conversation->id)->where('action', 'tool.request')->count())->toBe(1)
        ->and(AuditLog::query()->where('conversation_id', $conversation->id)->where('action', 'tool.allowed')->count())->toBe(1)
        ->and(AuditLog::query()->where('conversation_id', $conversation->id)->where('action', 'tool.executed')->count())->toBe(1);

    Livewire::test(ConversationSidebar::class)
        ->assertSee('Integration flow');
});

it('persists allow approval and auto allows matching tool call on subsequent request', function () {
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
            return 'Runs shell command';
        }

        public function parameters(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string', 'description' => 'Command to run'],
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

            return new ToolResult(true, ['ran' => $input['command'] ?? null]);
        }
    };

    $orchestrator = new AgentOrchestrator(
        new SystemPromptBuilder([$tool]),
        new ContextManager,
        [$tool],
        null,
        app(PermissionManager::class),
        app(AuditLogger::class),
        fn (ApprovalRequest $request) => new ApprovalResponse($request->requestId, 'allow', true),
    );

    Prism::fake([
        TextResponseFake::make()
            ->withToolCalls([new ToolCall('call_1', 'shell', ['command' => 'php -v'])])
            ->withFinishReason(FinishReason::ToolCalls),
        TextResponseFake::make()->withText('first run complete'),
    ]);

    $firstResponse = $orchestrator->respond('run shell once', $conversation->id);

    Prism::fake([
        TextResponseFake::make()
            ->withToolCalls([new ToolCall('call_2', 'shell', ['command' => 'php -v'])])
            ->withFinishReason(FinishReason::ToolCalls),
        TextResponseFake::make()->withText('second run complete'),
    ]);

    $secondResponse = $orchestrator->respond('run shell twice', $conversation->id);
    $scope = 'conversation:'.$conversation->id;

    expect($firstResponse)->toBe('first run complete')
        ->and($secondResponse)->toBe('second run complete')
        ->and($tool->executions)->toBe(2)
        ->and(ToolPermission::query()->where('tool_name', 'shell')->where('scope', $scope)->where('permission', ToolPermissionLevel::Allow)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('conversation_id', $conversation->id)->where('action', 'tool.allowed')->count())->toBe(2)
        ->and(AuditLog::query()->where('conversation_id', $conversation->id)->where('action', 'tool.executed')->count())->toBe(2);

    Event::assertDispatchedTimes(ApprovalRequest::class, 1);
});

it('extracts memories from conversation facts and recalls preferences in system prompt', function () {
    $conversation = Conversation::factory()->create();
    $extractor = app(FactExtractor::class);
    $memoryService = app(MemoryService::class);

    $facts = $extractor->extract('My name is Alice. I prefer dark mode. I use neovim daily.', $conversation);
    $memoryService->store(MemoryType::Preference, 'global.editor_theme', 'solarized dark', null, 0.7);

    $prompt = (new SystemPromptBuilder([]))->build($conversation);

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

it('converts normalized context to prism messages and enforces truncation budget', function () {
    $manager = new ContextManager;

    $truncated = $manager->truncateMessages('System prompt', [
        ['role' => 'user', 'content' => str_repeat('a', 120)],
        ['role' => 'assistant', 'content' => str_repeat('b', 120)],
        ['role' => 'user', 'content' => 'newest'],
    ], 100);

    $prismMessages = $manager->toPrismMessages([
        ['role' => 'system', 'content' => 'rules'],
        ['role' => 'assistant', 'content' => 'answer'],
        ['role' => 'user', 'content' => 'question'],
    ]);

    expect($manager->estimateTokens('abc'))->toBeGreaterThan(0)
        ->and($truncated)->toHaveCount(2)
        ->and($truncated[0]['role'])->toBe('assistant')
        ->and($truncated[1]['content'])->toBe('newest')
        ->and($prismMessages[0])->toBeInstanceOf(SystemMessage::class)
        ->and($prismMessages[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($prismMessages[2])->toBeInstanceOf(UserMessage::class);
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

    $prompt = (new SystemPromptBuilder([$tool]))->build();

    expect($prompt)->toContain('You are Aegis, AI under your Aegis.')
        ->and($prompt)->toContain('Available tools:')
        ->and($prompt)->toContain('- inspect_tool: Inspects project files')
        ->and($prompt)->toContain('User preferences:')
        ->and($prompt)->toContain('- none');
});

it('reads provider model and timeout from aegis agent configuration', function () {
    config()->set('aegis.agent.default_provider', 'openai');
    config()->set('aegis.agent.default_model', 'gpt-4.1-mini');
    config()->set('aegis.agent.timeout', 45);

    $agent = new AegisAgent;

    expect($agent->provider())->toBe('openai')
        ->and($agent->model())->toBe('gpt-4.1-mini')
        ->and($agent->timeout())->toBe(45);
});
