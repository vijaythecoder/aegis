<?php

namespace App\Agent;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Timeout(10)]
#[MaxTokens(50)]
class TitleGeneratorAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Generate a short conversation title (max 6 words) for the user message below. Return ONLY the title text, no quotes, no punctuation at the end.';
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
