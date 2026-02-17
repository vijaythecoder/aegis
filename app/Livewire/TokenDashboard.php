<?php

namespace App\Livewire;

use App\Models\TokenUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

class TokenDashboard extends Component
{
    public string $period = '7d';

    public function setPeriod(string $period): void
    {
        $this->period = $period;
        $this->dispatch('refresh-charts',
            timeSeries: $this->dailyCosts,
            providers: $this->providerBreakdown,
            models: $this->modelBreakdown,
        );
    }

    public function getSummaryProperty(): array
    {
        $query = TokenUsage::query();
        $this->applyPeriodFilter($query);

        $totalCost = (float) $query->sum('estimated_cost');
        $totalTokens = (int) $query->sum('total_tokens');
        $totalRequests = $query->count();
        $avgCostPerRequest = $totalRequests > 0 ? $totalCost / $totalRequests : 0;

        return [
            'total_cost' => $totalCost,
            'total_tokens' => $totalTokens,
            'total_requests' => $totalRequests,
            'avg_cost_per_request' => $avgCostPerRequest,
        ];
    }

    public function getDailyCostsProperty(): array
    {
        $query = TokenUsage::query()
            ->selectRaw('DATE(created_at) as date, SUM(estimated_cost) as cost, SUM(total_tokens) as tokens')
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)');

        $this->applyPeriodFilter($query);

        return $query->get()
            ->map(fn ($row) => [
                'x' => $row->date,
                'y' => round((float) $row->cost, 4),
                'tokens' => (int) $row->tokens,
            ])
            ->values()
            ->all();
    }

    public function getProviderBreakdownProperty(): array
    {
        $query = TokenUsage::query()
            ->selectRaw('provider, SUM(estimated_cost) as cost, SUM(total_tokens) as tokens, COUNT(*) as requests')
            ->groupBy('provider')
            ->orderByRaw('SUM(estimated_cost) DESC');

        $this->applyPeriodFilter($query);

        return $query->get()
            ->map(fn ($row) => [
                'name' => $row->provider,
                'cost' => round((float) $row->cost, 4),
                'tokens' => (int) $row->tokens,
                'requests' => (int) $row->requests,
            ])
            ->values()
            ->all();
    }

    public function getModelBreakdownProperty(): array
    {
        $query = TokenUsage::query()
            ->selectRaw('provider, model, SUM(estimated_cost) as cost, SUM(total_tokens) as tokens, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, COUNT(*) as requests')
            ->groupBy('provider', 'model')
            ->orderByRaw('SUM(estimated_cost) DESC');

        $this->applyPeriodFilter($query);

        return $query->get()
            ->map(fn ($row) => [
                'provider' => $row->provider,
                'model' => $row->model,
                'cost' => round((float) $row->cost, 4),
                'tokens' => (int) $row->tokens,
                'prompt_tokens' => (int) $row->prompt,
                'completion_tokens' => (int) $row->completion,
                'requests' => (int) $row->requests,
            ])
            ->values()
            ->all();
    }

    public function getRecentUsagesProperty(): Collection
    {
        return TokenUsage::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    public function render()
    {
        return view('livewire.token-dashboard');
    }

    private function applyPeriodFilter($query): void
    {
        $from = match ($this->period) {
            '24h' => Carbon::now()->subDay(),
            '7d' => Carbon::now()->subDays(7),
            '30d' => Carbon::now()->subDays(30),
            '90d' => Carbon::now()->subDays(90),
            'all' => null,
            default => Carbon::now()->subDays(7),
        };

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
    }
}
