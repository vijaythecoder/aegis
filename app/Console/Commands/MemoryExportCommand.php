<?php

namespace App\Console\Commands;

use App\Models\Memory;
use App\Models\Procedure;
use Illuminate\Console\Command;

class MemoryExportCommand extends Command
{
    protected $signature = 'aegis:memory:export
        {format=markdown : Export format (markdown or json)}
        {--output= : Output file path (defaults to stdout)}';

    protected $description = 'Export all memories and procedures as Markdown or JSON';

    public function handle(): int
    {
        $format = (string) $this->argument('format');
        $outputPath = $this->option('output');

        if (! in_array($format, ['markdown', 'json'])) {
            $this->error("Invalid format '{$format}'. Use 'markdown' or 'json'.");

            return self::FAILURE;
        }

        $memories = Memory::query()->orderBy('type')->orderBy('key')->get();
        $procedures = Procedure::query()->where('is_active', true)->orderBy('trigger')->get();

        $content = $format === 'json'
            ? $this->exportJson($memories, $procedures)
            : $this->exportMarkdown($memories, $procedures);

        if ($outputPath) {
            file_put_contents($outputPath, $content);
            $this->info("Exported to {$outputPath}");
        } else {
            $this->info($content);
        }

        return self::SUCCESS;
    }

    private function exportMarkdown($memories, $procedures): string
    {
        $lines = ['# Aegis Memory Export', '', 'Generated: '.now()->toDateTimeString(), ''];

        $grouped = $memories->groupBy(fn (Memory $m) => $m->type->value);

        foreach ($grouped as $type => $items) {
            $lines[] = '## '.ucfirst($type).'s';
            $lines[] = '';

            foreach ($items as $memory) {
                $lines[] = "- **{$memory->key}**: {$memory->value} (confidence: {$memory->confidence})";

                if ($memory->previous_value) {
                    $lines[] = "  - _Previously_: {$memory->previous_value}";
                }
            }

            $lines[] = '';
        }

        if ($procedures->isNotEmpty()) {
            $lines[] = '## Procedures';
            $lines[] = '';

            foreach ($procedures as $procedure) {
                $lines[] = "- **When**: {$procedure->trigger} → {$procedure->instruction}";
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function exportJson($memories, $procedures): string
    {
        $data = [
            'exported_at' => now()->toIso8601String(),
            'memories' => $memories->map(fn (Memory $m) => [
                'type' => $m->type->value,
                'key' => $m->key,
                'value' => $m->value,
                'previous_value' => $m->previous_value,
                'confidence' => $m->confidence,
                'created_at' => $m->created_at?->toIso8601String(),
                'updated_at' => $m->updated_at?->toIso8601String(),
            ])->values()->all(),
            'procedures' => $procedures->map(fn (Procedure $p) => [
                'trigger' => $p->trigger,
                'instruction' => $p->instruction,
                'source' => $p->source,
            ])->values()->all(),
        ];

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
