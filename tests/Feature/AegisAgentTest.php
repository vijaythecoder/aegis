<?php

use App\Agent\AegisAgent;
use App\Agent\AgentOrchestrator;
use App\Agent\ContextManager;
use App\Agent\Contracts\ToolInterface;
use App\Agent\SystemPromptBuilder;
use App\Agent\ToolResult;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\Agent as AgentContract;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\ToolCall;

uses(RefreshDatabase::class);

it('instantiates aegis agent and implements laravel ai agent contract', function () {
    $agent = new AegisAgent;

    expect($agent)
        ->toBeInstanceOf(AgentContract::class)
        ->and((string) $agent->instructions())->toContain('Aegis');
});

it('discovers registered tools in orchestrator', function () {
    $tool = new class implements ToolInterface
    {
        public function name(): string
        {
            return 'echo_tool';
        }

        public function description(): string
        {
            return 'Echoes a value';
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

        public function execute(array $input): ToolResult
        {
            return new ToolResult(true, $input['value'] ?? null);
        }

        public function requiredPermission(): string
        {
            return 'read';
        }
    };

    $orchestrator = new AgentOrchestrator(
        new SystemPromptBuilder([$tool]),
        new ContextManager,
        [$tool],
    );

    expect($orchestrator->toolNames())->toBe(['echo_tool']);
});

it('truncates oldest messages when context budget is exceeded', function () {
    $manager = new ContextManager;

    $messages = [
        ['role' => 'user', 'content' => str_repeat('a', 120)],
        ['role' => 'assistant', 'content' => str_repeat('b', 120)],
        ['role' => 'user', 'content' => str_repeat('c', 120)],
    ];

    $truncated = $manager->truncateMessages('System prompt', $messages, 100);

    expect($truncated)->toHaveCount(2)
        ->and($truncated[0]['content'])->toBe(str_repeat('b', 120))
        ->and($truncated[1]['content'])->toBe(str_repeat('c', 120));
});

it('passes configured max steps to prism request', function () {
    config()->set('aegis.agent.max_steps', 2);

    $conversation = Conversation::factory()->create();

    $fake = Prism::fake([
        TextResponseFake::make()->withText('Done'),
    ]);

    $orchestrator = new AgentOrchestrator(
        new SystemPromptBuilder([]),
        new ContextManager,
        [],
    );

    $result = $orchestrator->respond('Hello there', $conversation->id);

    expect($result)->toBe('Done');

    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(1)
            ->and($requests[0]->maxSteps())->toBe(2);
    });
});

it('executes tool calls in loop and stores tool result message', function () {
    $conversation = Conversation::factory()->create();

    $tool = new class implements ToolInterface
    {
        public function name(): string
        {
            return 'echo_tool';
        }

        public function description(): string
        {
            return 'Echoes a value';
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

        public function execute(array $input): ToolResult
        {
            return new ToolResult(true, 'echo: '.$input['value']);
        }

        public function requiredPermission(): string
        {
            return 'read';
        }
    };

    Prism::fake([
        TextResponseFake::make()
            ->withToolCalls([
                new ToolCall('call_1', 'echo_tool', ['value' => 'hello']),
            ])
            ->withFinishReason(FinishReason::ToolCalls),
        TextResponseFake::make()->withText('Final answer'),
    ]);

    $orchestrator = new AgentOrchestrator(
        new SystemPromptBuilder([$tool]),
        new ContextManager,
        [$tool],
    );

    $result = $orchestrator->respond('Use tool', $conversation->id);

    expect($result)->toBe('Final answer')
        ->and(Message::where('conversation_id', $conversation->id)->where('role', MessageRole::Tool)->count())->toBe(1)
        ->and(Message::where('conversation_id', $conversation->id)->where('role', MessageRole::Assistant)->count())->toBe(1);
});

it('retries llm call on transient errors', function () {
    config()->set('aegis.agent.max_retries', 3);

    $conversation = Conversation::factory()->create();

    $attempts = 0;

    $orchestrator = new AgentOrchestrator(
        new SystemPromptBuilder([]),
        new ContextManager,
        [],
        function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                throw new RuntimeException('temporary failure');
            }

            return TextResponseFake::make()->withText('Recovered');
        },
    );

    $result = $orchestrator->respond('retry me', $conversation->id);

    expect($result)->toBe('Recovered')
        ->and($attempts)->toBe(3);
});
