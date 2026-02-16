<?php

namespace App\Agent;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class PlanningAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a planning assistant. Given a user request, create a brief numbered plan (3-5 steps max) of what actions to take. Be concise. Only output the plan steps, nothing else.';
    }

    public function provider(): string
    {
        return (string) config('aegis.agent.summary_provider', 'anthropic');
    }

    public function model(): string
    {
        return (string) config('aegis.agent.summary_model', 'claude-3-5-haiku-latest');
    }
}
