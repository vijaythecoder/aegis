<?php

namespace App\Agent;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class ReflectionAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are a quality reviewer. Given a user query and an AI response, evaluate if the response is correct and complete.
Reply with exactly one of:
- "APPROVED: <brief reason>" if the response is acceptable
- "NEEDS_REVISION: <specific issue>" if the response has problems
Be concise. Only output one line.
PROMPT;
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
