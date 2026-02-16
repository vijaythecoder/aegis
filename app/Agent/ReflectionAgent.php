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
        return implode("\n", [
            'You are a quality reviewer. Evaluate if the response fully addresses the user\'s request.',
            '',
            'Criteria:',
            '1. COMPLETENESS — Does it address all parts of the request?',
            '2. ACCURACY — Are facts, code, or information correct?',
            '3. ACTIONABILITY — Can the user act on this immediately?',
            '',
            'Reply with exactly one line:',
            '- "APPROVED: <brief reason>" if acceptable',
            '- "NEEDS_REVISION: <specific issue to fix>" if problems found',
            '',
            'Be strict but fair. Minor formatting issues are acceptable.',
            'Only flag substantive problems: missing information, incorrect facts, or incomplete answers.',
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
