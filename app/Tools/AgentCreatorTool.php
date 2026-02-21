<?php

namespace App\Tools;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Skill;
use App\Services\SkillSuggestionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class AgentCreatorTool implements Tool
{
    private const MAX_AGENTS = 10;

    public function name(): string
    {
        return 'manage_agents';
    }

    public function requiredPermission(): string
    {
        return 'write';
    }

    public function description(): Stringable|string
    {
        return 'Create, update, list, or delete AI agents. Use this when the user wants to create a new agent '
            .'(e.g., "I want a fitness coach", "Create me a tax advisor"), modify an existing agent\'s personality or skills, '
            .'list available agents, or remove an agent. '
            .'When creating, provide a descriptive persona with personality traits and expertise. '
            .'Suggested skills are matched by slug from the built-in skill library.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['create', 'update', 'list', 'delete'])->description('The action to perform.')->required(),
            'name' => $schema->string()->description('Display name for the agent (required for create, optional for update). Max 50 chars.'),
            'persona' => $schema->string()->description('Personality instructions — who the agent is, their expertise, communication style (required for create).'),
            'suggested_skills' => $schema->array()->description('Array of skill slugs to attach. Available: research-assistant, writing-coach, data-analyst, schedule-manager, finance-tracker, health-fitness, learning-guide.'),
            'suggested_tools' => $schema->array()->description('Array of tool names to allow this agent to use.'),
            'avatar' => $schema->string()->description('Single emoji representing the agent. Default: 🤖'),
            'agent_id' => $schema->integer()->description('ID of the agent to update or delete.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $action = (string) $request->string('action');

        return match ($action) {
            'create' => $this->createAgent($request),
            'update' => $this->updateAgent($request),
            'list' => $this->listAgents(),
            'delete' => $this->deleteAgent($request),
            default => "Unknown action '{$action}'. Use: create, update, list, delete.",
        };
    }

    private function createAgent(Request $request): string
    {
        $name = trim((string) $request->string('name'));
        $persona = trim((string) $request->string('persona'));

        if ($name === '' || $persona === '') {
            return 'Error: Both name and persona are required to create an agent.';
        }

        if (mb_strlen($name) > 50) {
            return 'Error: Agent name must be 50 characters or fewer.';
        }

        if (Agent::query()->count() >= self::MAX_AGENTS) {
            return 'Error: Maximum of '.self::MAX_AGENTS.' agents reached. Delete an existing agent first.';
        }

        $slug = Str::slug($name);

        if (Agent::query()->where('slug', $slug)->exists()) {
            return "Error: An agent with the name \"{$name}\" already exists. Choose a different name.";
        }

        $avatar = trim((string) $request->string('avatar'));

        $agent = Agent::query()->create([
            'name' => $name,
            'slug' => $slug,
            'avatar' => $avatar !== '' ? $avatar : '🤖',
            'persona' => $persona,
            'is_active' => true,
        ]);

        $suggestedSlugs = $request->array('suggested_skills');

        if ($suggestedSlugs === []) {
            $suggested = app(SkillSuggestionService::class)->suggestForPersona($persona);

            if ($suggested->isNotEmpty()) {
                $agent->skills()->sync($suggested->pluck('id'));
            }
        } else {
            $this->attachSkills($agent, $request);
        }

        $this->attachTools($agent, $request);

        $conversation = Conversation::query()->create([
            'agent_id' => $agent->id,
            'title' => "Chat with {$agent->name}",
        ]);

        $skillNames = $agent->skills()->pluck('name')->implode(', ');
        $skillInfo = $skillNames !== '' ? " with skills: {$skillNames}" : '';

        return "Created agent \"{$agent->name}\" {$agent->avatar}{$skillInfo}. "
            ."Conversation started (ID: {$conversation->id}). "
            .'You can chat with them in the sidebar, or visit Settings > Agents to customize.';
    }

    private function updateAgent(Request $request): string
    {
        $agentId = $request->integer('agent_id');

        if ($agentId === 0) {
            return 'Error: agent_id is required for update.';
        }

        $agent = Agent::query()->find($agentId);

        if ($agent === null) {
            return "Error: Agent with ID {$agentId} not found.";
        }

        $updates = [];
        $name = trim((string) $request->string('name'));
        $persona = trim((string) $request->string('persona'));
        $avatar = trim((string) $request->string('avatar'));

        if ($name !== '') {
            $updates['name'] = $name;
            $updates['slug'] = Str::slug($name);
        }

        if ($persona !== '') {
            $updates['persona'] = $persona;
        }

        if ($avatar !== '') {
            $updates['avatar'] = $avatar;
        }

        if ($updates !== []) {
            $agent->update($updates);
        }

        $this->attachSkills($agent, $request);
        $this->attachTools($agent, $request);

        $agent->refresh();

        return "Updated agent \"{$agent->name}\". Changes applied successfully.";
    }

    private function listAgents(): string
    {
        $agents = Agent::query()
            ->where('is_active', true)
            ->with('skills')
            ->orderBy('name')
            ->get();

        if ($agents->isEmpty()) {
            return 'No active agents found. Create one with the "create" action.';
        }

        $lines = ["Active agents ({$agents->count()}/".self::MAX_AGENTS.'):'];

        foreach ($agents as $agent) {
            $skills = $agent->skills->pluck('name')->implode(', ');
            $skillInfo = $skills !== '' ? " [Skills: {$skills}]" : '';
            $lines[] = "- {$agent->avatar} {$agent->name} (ID: {$agent->id}){$skillInfo}";
        }

        return implode("\n", $lines);
    }

    private function deleteAgent(Request $request): string
    {
        $agentId = $request->integer('agent_id');

        if ($agentId === 0) {
            return 'Error: agent_id is required for delete.';
        }

        $agent = Agent::query()->find($agentId);

        if ($agent === null) {
            return "Error: Agent with ID {$agentId} not found.";
        }

        $name = $agent->name;
        $agent->skills()->detach();
        $agent->tools()->delete();
        $agent->delete();

        return "Deleted agent \"{$name}\".";
    }

    private function attachSkills(Agent $agent, Request $request): void
    {
        $slugs = $request->array('suggested_skills');

        if ($slugs === []) {
            return;
        }

        $skills = Skill::query()
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->pluck('id');

        if ($skills->isNotEmpty()) {
            $agent->skills()->sync($skills);
        }
    }

    private function attachTools(Agent $agent, Request $request): void
    {
        $toolNames = $request->array('suggested_tools');

        if ($toolNames === []) {
            return;
        }

        $registry = app(ToolRegistry::class);
        $validTools = [];

        foreach ($toolNames as $toolName) {
            if ($registry->get($toolName) !== null) {
                $validTools[] = $toolName;
            }
        }

        if ($validTools !== []) {
            $agent->tools()->delete();
            foreach ($validTools as $toolClass) {
                $agent->tools()->create(['tool_class' => $toolClass]);
            }
        }
    }
}
