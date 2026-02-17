<div class="max-w-5xl mx-auto px-6 py-8 space-y-6">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-display font-bold text-aegis-text">Token Usage</h2>
        <a href="{{ route('chat') }}" class="text-xs text-aegis-text-dim hover:text-aegis-text transition-colors">&larr; Back to Chat</a>
    </div>

    @if($this->summary['total_requests'] === 0)
        <div class="bg-aegis-850 border border-aegis-border rounded-xl p-12 text-center">
            <svg class="w-10 h-10 mx-auto mb-3 text-aegis-text-dim/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20V10"/>
                <path d="M18 20V4"/>
                <path d="M6 20v-4"/>
            </svg>
            <p class="text-sm text-aegis-text-dim">No usage data recorded yet.</p>
            <p class="text-xs text-aegis-text-dim/60 mt-1">Start a conversation to see your token usage here.</p>
        </div>
    @else
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="bg-aegis-850 border border-aegis-border rounded-xl p-5">
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-7 h-7 rounded-lg bg-emerald-500/10 flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="1" x2="12" y2="23"/>
                            <path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                        </svg>
                    </span>
                    <span class="text-xs text-aegis-text-dim">Total Cost</span>
                </div>
                <p class="text-xl font-mono font-semibold text-aegis-text">${{ number_format($this->summary['total_cost'], 4) }}</p>
            </div>

            <div class="bg-aegis-850 border border-aegis-border rounded-xl p-5">
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-7 h-7 rounded-lg bg-indigo-500/10 flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="4" y1="9" x2="20" y2="9"/>
                            <line x1="4" y1="15" x2="20" y2="15"/>
                            <line x1="10" y1="3" x2="8" y2="21"/>
                            <line x1="16" y1="3" x2="14" y2="21"/>
                        </svg>
                    </span>
                    <span class="text-xs text-aegis-text-dim">Total Tokens</span>
                </div>
                <p class="text-xl font-mono font-semibold text-aegis-text">{{ number_format($this->summary['total_tokens']) }}</p>
            </div>

            <div class="bg-aegis-850 border border-aegis-border rounded-xl p-5">
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-7 h-7 rounded-lg bg-amber-500/10 flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                        </svg>
                    </span>
                    <span class="text-xs text-aegis-text-dim">Total Requests</span>
                </div>
                <p class="text-xl font-mono font-semibold text-aegis-text">{{ number_format($this->summary['total_requests']) }}</p>
            </div>

            <div class="bg-aegis-850 border border-aegis-border rounded-xl p-5">
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-7 h-7 rounded-lg bg-violet-500/10 flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-violet-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                    </span>
                    <span class="text-xs text-aegis-text-dim">Avg Cost / Request</span>
                </div>
                <p class="text-xl font-mono font-semibold text-aegis-text">${{ number_format($this->summary['avg_cost_per_request'], 4) }}</p>
            </div>
        </div>

        <div class="flex items-center gap-1.5">
            @foreach(['24h' => '24h', '7d' => '7d', '30d' => '30d', '90d' => '90d', 'all' => 'All'] as $value => $label)
                <button
                    wire:click="setPeriod('{{ $value }}')"
                    class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors {{ $period === $value ? 'bg-aegis-accent text-aegis-900' : 'bg-aegis-850 text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover' }}"
                >{{ $label }}</button>
            @endforeach
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            <div class="lg:col-span-2 bg-aegis-850 border border-aegis-border rounded-xl p-5">
                <h3 class="text-sm font-semibold text-aegis-text mb-4">Cost Over Time</h3>
                <div
                    wire:ignore
                    x-data="{
                        init() {
                            let chart = null;
                            const el = this.$refs.timeChart;
                            const data = @js($this->dailyCosts);

                            const render = (seriesData) => {
                                const opts = {
                                    chart: {
                                        type: 'area',
                                        height: 260,
                                        background: 'transparent',
                                        toolbar: { show: false },
                                        zoom: { enabled: false },
                                        fontFamily: 'inherit',
                                    },
                                    series: [{ name: 'Cost', data: seriesData.map(d => ({ x: d.x, y: d.y })) }],
                                    colors: ['#818cf8'],
                                    fill: {
                                        type: 'gradient',
                                        gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05, stops: [0, 95] },
                                    },
                                    stroke: { curve: 'smooth', width: 2 },
                                    dataLabels: { enabled: false },
                                    xaxis: {
                                        type: 'category',
                                        labels: { style: { colors: '#6b7280', fontSize: '10px' } },
                                        axisBorder: { show: false },
                                        axisTicks: { show: false },
                                    },
                                    yaxis: {
                                        labels: {
                                            style: { colors: '#6b7280', fontSize: '10px' },
                                            formatter: (v) => '$' + v.toFixed(4),
                                        },
                                    },
                                    grid: { borderColor: '#1f2937', strokeDashArray: 3 },
                                    tooltip: {
                                        theme: 'dark',
                                        y: { formatter: (v) => '$' + v.toFixed(4) },
                                        x: { show: true },
                                    },
                                    theme: { mode: 'dark' },
                                };

                                if (chart) {
                                    chart.updateOptions({ series: [{ name: 'Cost', data: seriesData.map(d => ({ x: d.x, y: d.y })) }] });
                                } else {
                                    chart = new ApexCharts(el, opts);
                                    chart.render();
                                }
                            };

                            render(data);

                            Livewire.on('refresh-charts', (params) => {
                                const p = Array.isArray(params) ? params[0] : params;
                                if (p.timeSeries) render(p.timeSeries);
                            });
                        }
                    }"
                >
                    <div x-ref="timeChart"></div>
                </div>
            </div>

            <div class="bg-aegis-850 border border-aegis-border rounded-xl p-5">
                <h3 class="text-sm font-semibold text-aegis-text mb-4">By Provider</h3>
                <div
                    wire:ignore
                    x-data="{
                        init() {
                            let chart = null;
                            const el = this.$refs.donutChart;
                            const data = @js($this->providerBreakdown);
                            const palette = ['#818cf8', '#a78bfa', '#c084fc', '#f472b6', '#fb923c'];

                            const render = (items) => {
                                const labels = items.map(i => i.name);
                                const series = items.map(i => parseFloat(i.cost));

                                const opts = {
                                    chart: {
                                        type: 'donut',
                                        height: 260,
                                        background: 'transparent',
                                        fontFamily: 'inherit',
                                    },
                                    series: series,
                                    labels: labels,
                                    colors: palette.slice(0, labels.length),
                                    plotOptions: {
                                        pie: {
                                            donut: { size: '65%' },
                                        },
                                    },
                                    dataLabels: { enabled: false },
                                    legend: {
                                        position: 'bottom',
                                        labels: { colors: '#9ca3af' },
                                        fontSize: '11px',
                                        markers: { size: 6, offsetX: -3 },
                                        itemMargin: { horizontal: 8 },
                                    },
                                    tooltip: {
                                        theme: 'dark',
                                        y: { formatter: (v) => '$' + v.toFixed(4) },
                                    },
                                    stroke: { show: false },
                                    theme: { mode: 'dark' },
                                };

                                if (chart) {
                                    chart.updateOptions({ series: series, labels: labels });
                                } else {
                                    chart = new ApexCharts(el, opts);
                                    chart.render();
                                }
                            };

                            if (data.length) render(data);

                            Livewire.on('refresh-charts', (params) => {
                                const p = Array.isArray(params) ? params[0] : params;
                                if (p.providers) render(p.providers);
                            });
                        }
                    }"
                >
                    <div x-ref="donutChart"></div>
                    @if(empty($this->providerBreakdown))
                        <p class="text-xs text-aegis-text-dim text-center py-8">No provider data yet.</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="bg-aegis-850 border border-aegis-border rounded-xl">
            <div class="px-5 py-3 border-b border-aegis-border">
                <h3 class="text-sm font-semibold text-aegis-text">Model Breakdown</h3>
            </div>
            @if(empty($this->modelBreakdown))
                <div class="px-5 py-6 text-center text-sm text-aegis-text-dim">No model data yet.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="border-b border-aegis-border text-aegis-text-dim">
                                <th class="px-5 py-2.5 text-left font-medium">Model</th>
                                <th class="px-5 py-2.5 text-left font-medium">Provider</th>
                                <th class="px-5 py-2.5 text-right font-medium">Requests</th>
                                <th class="px-5 py-2.5 text-right font-medium">Prompt</th>
                                <th class="px-5 py-2.5 text-right font-medium">Completion</th>
                                <th class="px-5 py-2.5 text-right font-medium">Total Tokens</th>
                                <th class="px-5 py-2.5 text-right font-medium">Cost</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-aegis-border">
                            @foreach($this->modelBreakdown as $row)
                                <tr class="hover:bg-aegis-surface-hover transition-colors">
                                    <td class="px-5 py-2.5 font-mono text-aegis-text">{{ $row['model'] }}</td>
                                    <td class="px-5 py-2.5 text-aegis-text-dim">{{ $row['provider'] }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-aegis-text">{{ number_format($row['requests']) }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-aegis-text-dim">{{ number_format($row['prompt_tokens']) }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-aegis-text-dim">{{ number_format($row['completion_tokens']) }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-aegis-text">{{ number_format($row['tokens']) }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-aegis-accent">${{ number_format($row['cost'], 4) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="bg-aegis-850 border border-aegis-border rounded-xl">
            <div class="px-5 py-3 border-b border-aegis-border">
                <h3 class="text-sm font-semibold text-aegis-text">Recent Usage</h3>
            </div>
            @if($this->recentUsages->isEmpty())
                <div class="px-5 py-6 text-center text-sm text-aegis-text-dim">No recent usage entries.</div>
            @else
                <div class="divide-y divide-aegis-border max-h-96 overflow-y-auto">
                    @foreach($this->recentUsages as $usage)
                        <div class="px-5 py-2.5 flex items-center gap-4 text-xs">
                            <span class="w-20 shrink-0 text-aegis-text-dim">{{ $usage->created_at->format('M d H:i') }}</span>
                            <span class="w-24 shrink-0 text-aegis-text-dim">{{ $usage->provider }}</span>
                            <span class="flex-1 font-mono text-aegis-text truncate">{{ $usage->model }}</span>
                            <span class="w-20 shrink-0 text-right font-mono text-aegis-text-dim">{{ number_format($usage->total_tokens) }} tok</span>
                            <span class="w-16 shrink-0 text-right font-mono text-aegis-accent">${{ number_format($usage->estimated_cost, 4) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
