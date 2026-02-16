<?php

namespace App\Agent;

class PlanningStep
{
    private const ACTION_KEYWORDS = [
        'create', 'modify', 'delete', 'update', 'build', 'fix', 'implement',
        'write', 'read', 'search', 'find', 'analyze', 'run', 'execute',
        'install', 'refactor', 'migrate', 'deploy', 'configure', 'set up',
        'debug', 'test', 'remove', 'add', 'change', 'move', 'rename',
    ];

    public function generate(string $userMessage): ?string
    {
        if (! config('aegis.agent.planning_enabled', true)) {
            return null;
        }

        if (! $this->isComplex($userMessage)) {
            return null;
        }

        try {
            $agent = app(PlanningAgent::class);
            $response = $agent->prompt($userMessage);
            $plan = trim($response->text ?? '');

            return $plan !== '' ? $plan : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function isComplex(string $message): bool
    {
        $wordCount = str_word_count($message);

        if ($wordCount < 5) {
            return false;
        }

        $lowerMessage = strtolower($message);

        foreach (self::ACTION_KEYWORDS as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                return true;
            }
        }

        return $wordCount >= 20;
    }
}
