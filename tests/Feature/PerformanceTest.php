<?php

use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('keeps cold start under three seconds', function () {
    $start = microtime(true);

    test()->artisan('route:list')->assertExitCode(0);

    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($elapsedMs)->toBeLessThan(3000.0);
});

it('keeps fts5 message search under one hundred milliseconds at ten thousand records', function () {
    $conversation = Conversation::query()->create([
        'title' => 'Performance',
    ]);

    $now = now();
    $rows = [];

    for ($i = 1; $i <= 10000; $i++) {
        $rows[] = [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $i === 10000
                ? 'latency target sentineltoken exactneedle'
                : "message {$i} baseline content",
            'tool_name' => null,
            'tool_call_id' => null,
            'tool_result' => null,
            'tokens_used' => 4,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($rows) === 1000) {
            DB::table('messages')->insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        DB::table('messages')->insert($rows);
    }

    $start = microtime(true);
    $result = DB::select(
        'SELECT rowid FROM messages_fts WHERE messages_fts MATCH ? LIMIT 20',
        ['sentineltoken'],
    );
    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($result)->not->toBeEmpty()
        ->and($elapsedMs)->toBeLessThan(100.0);
});

it('runs benchmark command successfully', function () {
    test()->artisan('aegis:benchmark', ['--records' => 1000])->assertExitCode(0);
});
