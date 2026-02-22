<?php

namespace App\Agent;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Timeout(30)]
#[UseCheapestModel]
class ProjectReviewAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return implode("\n", [
            'You are a project review agent. Given a list of active projects with their tasks, deadlines, and progress, generate concise nudge messages for projects that need attention.',
            'Focus on:',
            '- Stalled projects (no task activity in 3+ days)',
            '- Upcoming deadlines (within 48 hours)',
            '- Tasks stuck in_progress for too long (2+ days without completion)',
            '- Projects with no assigned tasks',
            '- Potential connections between projects based on overlapping keywords or themes in their tasks and knowledge',
            'For each nudge, assign an urgency level:',
            '- "low" for minor reminders or suggestions',
            '- "medium" for stalled projects or tasks needing follow-up',
            '- "high" for imminent deadlines or critical blockers',
            'If no projects need attention, return an empty nudges array.',
            'Be concise — each message should be 1-2 sentences max.',
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

    public function schema(JsonSchema $schema): array
    {
        return [
            'nudges' => $schema->array()->items(
                $schema->object([
                    'project_id' => $schema->integer()->required()->description('The project ID this nudge relates to'),
                    'message' => $schema->string()->required()->description('A concise nudge message (1-2 sentences)'),
                    'urgency' => $schema->string()->enum(['low', 'medium', 'high'])->required()->description('Urgency level of this nudge'),
                ])
            )->required(),
        ];
    }
}
