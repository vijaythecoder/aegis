<?php

use App\Agent\ContextManager;
use App\Agent\ConversationSummarizer;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

uses(RefreshDatabase::class);

it('keeps long conversations within the context window and preserves newest messages', function () {
    $manager = new ContextManager;
    $messages = makeMessages(160, 220);

    $truncated = $manager->truncateMessages('System prompt '.str_repeat('S', 200), $messages, 8000);

    expect($manager->totalTokensUsed('System prompt '.str_repeat('S', 200), $truncated))->toBeLessThanOrEqual(8000)
        ->and($truncated)->not->toBeEmpty()
        ->and(last($truncated)['content'])->toBe(last($messages)['content']);
});

it('generates and stores conversation summaries for dropped chunks', function () {
    $conversation = Conversation::factory()->create(['summary' => null]);
    $summarizer = new ConversationSummarizer;

    $messages = makeMessages(24, 60);

    Prism::fake([
        TextResponseFake::make()->withText('Key decisions: use sqlite for tests. Facts: user prefers concise output.'),
    ]);

    $summary = $summarizer->summarize($messages);
    $summarizer->updateConversationSummary($conversation->id, $summary);

    expect($summary)->toContain('Key decisions')
        ->and($summary)->toContain('user prefers concise output')
        ->and($conversation->fresh()->summary)->toBe($summary);
});

it('estimates and totals tokens consistently', function () {
    $manager = new ContextManager;

    $systemPrompt = 'System prompt';
    $messages = [
        ['role' => 'user', 'content' => 'abcd'],
        ['role' => 'assistant', 'content' => 'abcdefgh'],
        ['role' => 'user', 'content' => 'abcdefghijkl'],
    ];

    $expected = $manager->estimateTokens($systemPrompt)
        + $manager->estimateTokens('abcd')
        + $manager->estimateTokens('abcdefgh')
        + $manager->estimateTokens('abcdefghijkl');

    expect($manager->estimateTokens('abcdefgh'))->toBe(2)
        ->and($manager->totalTokensUsed($systemPrompt, $messages))->toBe($expected);
});

it('allocates context budget by configured model window ratios', function () {
    $manager = new ContextManager;
    $messages = makeMessages(40, 500);

    $budget = $manager->allocateBudget(8000);
    $truncated = $manager->truncateMessages('System '.str_repeat('x', 600), $messages, 8000);
    $messageTokens = array_sum(array_map(
        fn (array $message): int => $manager->estimateTokens((string) ($message['content'] ?? '')),
        $truncated
    ));

    expect($budget)->toBe([
        'system_prompt' => 1200,
        'memories' => 800,
        'summary' => 800,
        'messages' => 4800,
        'reserve' => 400,
    ])
        ->and($messageTokens)->toBeLessThanOrEqual($budget['messages'])
        ->and(last($truncated)['content'])->toBe(last($messages)['content']);
});

it('compresses verbose tool outputs while preserving key information', function () {
    $manager = new ContextManager;
    $largeJson = json_encode([
        'path' => '/tmp/report.json',
        'items' => range(1, 150),
        'status' => 'ok',
        'meta' => ['duration_ms' => 1234, 'source' => 'shell'],
    ], JSON_THROW_ON_ERROR);

    $messages = [
        [
            'role' => 'tool',
            'tool_name' => 'bash',
            'content' => "Exit: 0\n".str_repeat('line output from command'.PHP_EOL, 300),
        ],
        [
            'role' => 'tool',
            'tool_name' => 'read',
            'content' => "File: /app/Agent/ContextManager.php\n".str_repeat('public function run() {}'.PHP_EOL, 220),
        ],
        [
            'role' => 'tool',
            'tool_name' => 'search',
            'content' => $largeJson,
        ],
    ];

    $compressed = $manager->compressToolResults($messages);

    expect(strlen($compressed[0]['content']))->toBeLessThan((int) floor(strlen($messages[0]['content']) / 2))
        ->and($compressed[0]['content'])->toContain('Command result')
        ->and(strlen($compressed[1]['content']))->toBeLessThan((int) floor(strlen($messages[1]['content']) / 2))
        ->and($compressed[1]['content'])->toContain('File read')
        ->and($compressed[2]['content'])->toContain('JSON result');
});

it('builds sliding window context with summary prefix and recent messages', function () {
    $manager = new ContextManager;
    $messages = makeMessages(50, 220);
    $summary = 'Key decisions from earlier context: keep sqlite and compact output.';

    $contextMessages = $manager->buildContextWindow(
        'System prompt '.str_repeat('A', 300),
        $messages,
        6000,
        $summary,
        ['prefers concise responses', 'project uses Pest']
    );

    $systemMessages = array_values(array_filter(
        $contextMessages,
        fn (array $message): bool => ($message['role'] ?? null) === 'system'
    ));
    $hasSummaryPrefix = collect($systemMessages)
        ->contains(fn (array $message): bool => str_contains((string) ($message['content'] ?? ''), 'Conversation summary'));

    expect($contextMessages)->not->toBeEmpty()
        ->and($systemMessages)->not->toBeEmpty()
        ->and($hasSummaryPrefix)->toBeTrue()
        ->and(last($contextMessages)['content'])->toBe(last($messages)['content'])
        ->and($manager->totalTokensUsed('System prompt '.str_repeat('A', 300), $contextMessages))->toBeLessThanOrEqual(6000);
});

function makeMessages(int $count, int $size): array
{
    return collect(range(1, $count))
        ->map(fn (int $index): array => [
            'role' => $index % 2 === 0 ? 'assistant' : 'user',
            'content' => "message {$index} ".str_repeat(chr(97 + ($index % 26)), $size),
        ])
        ->all();
}
