<?php

namespace App\Agent;

use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Timeout(20)]
class SkillContentAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return implode("\n", [
            'You generate concise skill instructions for a personal AI assistant.',
            'A "skill" is a knowledge package: markdown-formatted guidelines that tell an agent HOW to behave in a specific domain.',
            'Given a topic and supporting context (user conversations, memories), produce practical instructions.',
            'Format: clear, actionable markdown. Include key principles, common patterns, and specific preferences observed.',
            'Keep the output under 2500 tokens. Be specific and practical, not generic.',
            'Do NOT include greetings, disclaimers, or meta-commentary. Only the skill instructions.',
        ]);
    }

    public function provider(): string
    {
        return (string) (config('aegis.agent.summary_provider') ?: config('aegis.agent.default_provider', 'anthropic'));
    }

    public function model(): string
    {
        $model = (string) config('aegis.agent.summary_model');

        if ($model !== '') {
            return $model;
        }

        $provider = $this->provider();

        return (string) config("aegis.providers.{$provider}.default_model", '');
    }
}
