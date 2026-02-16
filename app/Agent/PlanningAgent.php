<?php

namespace App\Agent;

use App\Tools\ToolRegistry;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class PlanningAgent implements Agent
{
    use Promptable;

    public function __construct(private readonly ToolRegistry $toolRegistry) {}

    public function instructions(): Stringable|string
    {
        $toolDescriptions = $this->describeTools();

        return implode("\n", [
            'You are a planning assistant. Given a user request, create a concise execution plan.',
            '',
            'Available tools the executor can use:',
            $toolDescriptions,
            '',
            'Rules:',
            '1. Each step must be one atomic action',
            '2. Reference specific tools when a step requires one',
            '3. Mark dependencies between steps (e.g., "needs step 1 result")',
            '4. Maximum 5 steps. If more needed, group related actions.',
            '5. For simple clarifications or conversations, output: SIMPLE_RESPONSE',
            '',
            'Output format:',
            'STEP 1: [action] using [tool_name]',
            'STEP 2: [action] using [tool_name] (needs: step 1)',
            '...',
        ]);
    }

    public function provider(): string
    {
        return (string) config('aegis.agent.summary_provider', config('aegis.agent.default_provider', 'anthropic'));
    }

    public function model(): string
    {
        return (string) config('aegis.agent.summary_model', '');
    }

    private function describeTools(): string
    {
        $tools = collect($this->toolRegistry->all())
            ->filter(fn (mixed $tool): bool => method_exists($tool, 'name') && method_exists($tool, 'description'))
            ->map(fn (mixed $tool): string => "- {$tool->name()}: {$tool->description()}")
            ->values();

        if ($tools->isEmpty()) {
            return '- (no tools available)';
        }

        return $tools->implode("\n");
    }
}
