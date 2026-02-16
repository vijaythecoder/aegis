<?php

namespace App\Agent;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Timeout(15)]
class MemoryExtractorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return implode("\n", [
            'Extract memorable facts, preferences, and notes from conversation exchanges.',
            'Types: "fact" (personal info like name, job, location, timezone), "preference" (likes, dislikes, tool preferences), "note" (project details, important context).',
            'Keys should be dot-notation identifiers like: user.name, user.timezone, user.preference.theme, project.aegis.stack.',
            'Only extract EXPLICIT information stated by the user. Do NOT infer or guess.',
            'If nothing worth remembering, return an empty memories array.',
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
            'memories' => $schema->array()->items(
                $schema->object([
                    'type' => $schema->string()->enum(['fact', 'preference', 'note'])->required(),
                    'key' => $schema->string()->required()->description('Dot-notation identifier like user.name, user.preference.theme'),
                    'value' => $schema->string()->required()->description('The extracted value'),
                ])
            )->required(),
        ];
    }
}
