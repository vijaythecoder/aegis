<?php

namespace App\Agent;

use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Timeout(20)]
class ConversationSummaryAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return implode("\n", [
            'Summarize this conversation in 2-4 sentences.',
            'Focus on: key topics discussed, decisions made, and any action items.',
            'Include specific technical details, project names, or preferences mentioned.',
            'Return ONLY the summary text.',
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
