<?php

namespace App\Agent;

class ReflectionStep
{
    public function reflect(string $userMessage, string $agentResponse): ReflectionResult
    {
        if (! config('aegis.agent.reflection_enabled', false)) {
            return ReflectionResult::approved();
        }

        try {
            $agent = app(ReflectionAgent::class);
            $prompt = "User query: {$userMessage}\n\nAI response: {$agentResponse}";
            $response = $agent->prompt($prompt);
            $text = trim($response->text ?? '');

            if ($text === '') {
                return ReflectionResult::approved();
            }

            if (str_starts_with(strtoupper($text), 'NEEDS_REVISION:')) {
                $feedback = trim(substr($text, strlen('NEEDS_REVISION:')));

                return ReflectionResult::needsRevision($feedback);
            }

            return ReflectionResult::approved();
        } catch (\Throwable) {
            return ReflectionResult::approved();
        }
    }
}
