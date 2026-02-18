<?php

namespace App\Agent;

use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Timeout(15)]
class ProfileSummaryAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return implode("\n", [
            'You are a profile summarizer. Given a list of facts, preferences, and notes about a user, produce a concise user profile summary (150-300 tokens max).',
            'Format: short sentences or phrases. Include name, timezone, tech stack, current projects, communication style, and key preferences.',
            'Do NOT invent information. Only use what is provided. If a field has no data, skip it.',
            'Return ONLY the summary text, no headers or labels.',
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
