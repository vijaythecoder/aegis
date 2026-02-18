<?php

namespace App\Agent;

use App\Models\Agent as AgentModel;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class AgentRegistry
{
    /**
     * Resolve an Agent model by ID into a DynamicAgent instance.
     *
     * @throws ModelNotFoundException
     */
    public function resolve(int $id): DynamicAgent
    {
        $agentModel = AgentModel::query()->where('is_active', true)->findOrFail($id);

        return app()->make(DynamicAgent::class, ['agentModel' => $agentModel]);
    }

    /**
     * Resolve an Agent model by slug into a DynamicAgent instance.
     *
     * @throws ModelNotFoundException
     */
    public function resolveBySlug(string $slug): DynamicAgent
    {
        $agentModel = AgentModel::query()->where('is_active', true)->where('slug', $slug)->firstOrFail();

        return app()->make(DynamicAgent::class, ['agentModel' => $agentModel]);
    }

    /**
     * Return the default AegisAgent singleton.
     * Falls back to resolving the 'aegis' slug DynamicAgent if it exists,
     * but the primary default is the hardcoded AegisAgent class.
     */
    public function resolveDefault(): AegisAgent
    {
        return app(AegisAgent::class);
    }

    /**
     * Get all active Agent models.
     */
    public function all(): Collection
    {
        return AgentModel::query()->where('is_active', true)->get();
    }

    /**
     * Resolve the appropriate agent for a conversation.
     * If the conversation has an agent_id, return a DynamicAgent.
     * Otherwise, return the default AegisAgent.
     */
    public function forConversation(Conversation $conversation): AegisAgent|DynamicAgent
    {
        if ($conversation->agent_id !== null) {
            try {
                return $this->resolve($conversation->agent_id);
            } catch (ModelNotFoundException) {
                // Agent was deleted or deactivated — fall back to default
                return $this->resolveDefault();
            }
        }

        return $this->resolveDefault();
    }
}
