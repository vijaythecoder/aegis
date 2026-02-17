<?php

namespace App\Console\Commands;

use App\Models\TokenUsage;
use Illuminate\Console\Command;

class AegisUsageCommand extends Command
{
    protected $signature = 'aegis:usage {--period=7d : Time period (24h, 7d, 30d, 90d, all)}';

    protected $description = 'Display token usage summary';

    public function handle(): int
    {
        $period = $this->option('period');

        $query = TokenUsage::query();
        $this->applyPeriodFilter($query, $period);

        $totalCost = (float) $query->sum('estimated_cost');
        $totalTokens = (int) $query->sum('total_tokens');
        $totalRequests = $query->count();

        $this->info("Token Usage Summary ({$period})");
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Cost', '$'.number_format($totalCost, 4)],
                ['Total Tokens', number_format($totalTokens)],
                ['Total Requests', number_format($totalRequests)],
                ['Avg Cost/Request', $totalRequests > 0 ? '$'.number_format($totalCost / $totalRequests, 4) : '$0.0000'],
            ]
        );

        $byProvider = TokenUsage::query()
            ->selectRaw('provider, SUM(estimated_cost) as cost, SUM(total_tokens) as tokens, COUNT(*) as requests')
            ->groupBy('provider')
            ->orderByRaw('SUM(estimated_cost) DESC');

        $this->applyPeriodFilter($byProvider, $period);
        $providerRows = $byProvider->get();

        if ($providerRows->isNotEmpty()) {
            $this->newLine();
            $this->info('By Provider:');
            $this->table(
                ['Provider', 'Cost', 'Tokens', 'Requests'],
                $providerRows->map(fn ($r) => [
                    $r->provider,
                    '$'.number_format((float) $r->cost, 4),
                    number_format((int) $r->tokens),
                    number_format((int) $r->requests),
                ])->all()
            );
        }

        $byModel = TokenUsage::query()
            ->selectRaw('provider, model, SUM(estimated_cost) as cost, SUM(total_tokens) as tokens, COUNT(*) as requests')
            ->groupBy('provider', 'model')
            ->orderByRaw('SUM(estimated_cost) DESC')
            ->limit(10);

        $this->applyPeriodFilter($byModel, $period);
        $modelRows = $byModel->get();

        if ($modelRows->isNotEmpty()) {
            $this->newLine();
            $this->info('By Model (top 10):');
            $this->table(
                ['Model', 'Provider', 'Cost', 'Tokens', 'Requests'],
                $modelRows->map(fn ($r) => [
                    $r->model,
                    $r->provider,
                    '$'.number_format((float) $r->cost, 4),
                    number_format((int) $r->tokens),
                    number_format((int) $r->requests),
                ])->all()
            );
        }

        return self::SUCCESS;
    }

    private function applyPeriodFilter($query, string $period): void
    {
        $from = match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            'all' => null,
            default => now()->subDays(7),
        };

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
    }
}
