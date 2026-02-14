<?php

namespace App\Console\Commands;

use App\Memory\MemoryService;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as CommandStatus;
use Symfony\Component\Process\Process;
use Throwable;

class AegisBenchmark extends Command
{
    protected $signature = 'aegis:benchmark {--records=10000 : Number of records for primary benchmarks}';

    protected $description = 'Run Aegis performance benchmarks';

    public function __construct(private readonly MemoryService $memoryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $records = max(1000, (int) $this->option('records'));
        $rows = [];
        $allPassed = true;

        $coldStart = $this->benchmarkColdStart();
        $rows[] = ['Cold start (php artisan route:list)', '-', $this->formatMs($coldStart['ms']), $coldStart['pass'] ? 'PASS' : 'FAIL'];
        $allPassed = $allPassed && $coldStart['pass'];

        foreach (array_values(array_unique([1000, $records])) as $count) {
            $fts = $this->benchmarkFtsSearch($count);
            $rows[] = ["FTS5 search", (string) $count, $this->formatMs($fts['ms']), $fts['pass'] ? 'PASS' : 'FAIL'];
            $allPassed = $allPassed && $fts['pass'];
        }

        $messageWrite = $this->benchmarkMessageWriteThroughput($records);
        $rows[] = ['Write throughput (messages)', (string) $records, $this->formatMs($messageWrite['ms']), $messageWrite['pass'] ? 'PASS' : 'FAIL'];
        $allPassed = $allPassed && $messageWrite['pass'];

        $memoryWrite = $this->benchmarkMemoryWriteThroughput($records);
        $rows[] = ['Write throughput (memories)', (string) $records, $this->formatMs($memoryWrite['ms']), $memoryWrite['pass'] ? 'PASS' : 'FAIL'];
        $allPassed = $allPassed && $memoryWrite['pass'];

        foreach (array_values(array_unique([1000, min(5000, $records), $records])) as $count) {
            $memorySearch = $this->benchmarkMemorySearch($count);
            $rows[] = ['Memory search (MemoryService::search)', (string) $count, $this->formatMs($memorySearch['ms']), $memorySearch['pass'] ? 'PASS' : 'FAIL'];
            $allPassed = $allPassed && $memorySearch['pass'];
        }

        $this->table(['Benchmark', 'Records', 'Time (ms)', 'Status'], $rows);

        return $allPassed ? CommandStatus::SUCCESS : CommandStatus::FAILURE;
    }

    private function benchmarkColdStart(): array
    {
        $start = microtime(true);
        $process = new Process([PHP_BINARY, 'artisan', 'route:list', '--no-ansi'], base_path());
        $process->run();
        $elapsedMs = (microtime(true) - $start) * 1000;

        return [
            'ms' => $elapsedMs,
            'pass' => $process->isSuccessful() && $elapsedMs < 3000.0,
        ];
    }

    private function benchmarkFtsSearch(int $count): array
    {
        return $this->withinRollback(function () use ($count): array {
            $conversation = Conversation::query()->create(['title' => 'Benchmark']);
            $now = now();
            $rows = [];

            for ($i = 1; $i <= $count; $i++) {
                $rows[] = [
                    'conversation_id' => $conversation->id,
                    'role' => 'user',
                    'content' => $i === $count ? 'benchneedle benchmark search target' : "benchmark message {$i}",
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
            $result = DB::select('SELECT rowid FROM messages_fts WHERE messages_fts MATCH ? LIMIT 20', ['benchneedle']);
            $elapsedMs = (microtime(true) - $start) * 1000;
            $thresholdMs = $count >= 10000 ? 100.0 : 200.0;

            return [
                'ms' => $elapsedMs,
                'pass' => $result !== [] && $elapsedMs < $thresholdMs,
            ];
        });
    }

    private function benchmarkMessageWriteThroughput(int $count): array
    {
        return $this->withinRollback(function () use ($count): array {
            $conversation = Conversation::query()->create(['title' => 'Benchmark']);
            $now = now();
            $rows = [];
            $start = microtime(true);

            for ($i = 1; $i <= $count; $i++) {
                $rows[] = [
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => "throughput message {$i}",
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

            $elapsedMs = (microtime(true) - $start) * 1000;
            $recordsPerSecond = $count / max(0.001, ($elapsedMs / 1000));

            return [
                'ms' => $elapsedMs,
                'pass' => $recordsPerSecond > 1000.0,
            ];
        });
    }

    private function benchmarkMemoryWriteThroughput(int $count): array
    {
        return $this->withinRollback(function () use ($count): array {
            $now = now();
            $rows = [];
            $start = microtime(true);

            for ($i = 1; $i <= $count; $i++) {
                $rows[] = [
                    'type' => 'fact',
                    'key' => "bench.fact.{$i}",
                    'value' => "benchmark memory {$i}",
                    'source' => 'benchmark',
                    'conversation_id' => null,
                    'confidence' => 1.0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($rows) === 1000) {
                    DB::table('memories')->insert($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                DB::table('memories')->insert($rows);
            }

            $elapsedMs = (microtime(true) - $start) * 1000;
            $recordsPerSecond = $count / max(0.001, ($elapsedMs / 1000));

            return [
                'ms' => $elapsedMs,
                'pass' => $recordsPerSecond > 1000.0,
            ];
        });
    }

    private function benchmarkMemorySearch(int $count): array
    {
        return $this->withinRollback(function () use ($count): array {
            $now = now();
            $rows = [];

            for ($i = 1; $i <= $count; $i++) {
                $rows[] = [
                    'type' => 'fact',
                    'key' => "bench.search.{$i}",
                    'value' => $i === $count ? 'memorybenchneedle matched term' : "memory {$i}",
                    'source' => 'benchmark',
                    'conversation_id' => null,
                    'confidence' => 1.0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($rows) === 1000) {
                    DB::table('memories')->insert($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                DB::table('memories')->insert($rows);
            }

            $start = microtime(true);
            $result = $this->memoryService->search('memorybenchneedle');
            $elapsedMs = (microtime(true) - $start) * 1000;

            return [
                'ms' => $elapsedMs,
                'pass' => $result->isNotEmpty() && $elapsedMs < 150.0,
            ];
        });
    }

    private function withinRollback(callable $callback): array
    {
        DB::beginTransaction();

        try {
            return $callback();
        } catch (Throwable $exception) {
            report($exception);

            return ['ms' => 0.0, 'pass' => false];
        } finally {
            DB::rollBack();
        }
    }

    private function formatMs(float $milliseconds): string
    {
        return number_format($milliseconds, 2);
    }
}
