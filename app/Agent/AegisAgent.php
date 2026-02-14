<?php

namespace App\Agent;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class AegisAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $name = (string) config('aegis.name', 'Aegis');
        $tagline = (string) config('aegis.tagline', 'AI under your Aegis');

        return "You are {$name}, {$tagline}.";
    }

    public function provider(): string
    {
        return (string) config('aegis.agent.default_provider', 'anthropic');
    }

    public function model(): string
    {
        return (string) config('aegis.agent.default_model', 'claude-sonnet-4-20250514');
    }

    public function timeout(): int
    {
        return (int) config('aegis.agent.timeout', 120);
    }
}
