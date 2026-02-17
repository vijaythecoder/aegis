<?php

namespace App\Agent\Middleware;

use App\Services\TokenTrackingService;
use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Throwable;

class TrackTokenUsage
{
    public function __construct(private readonly TokenTrackingService $trackingService) {}

    public function handle(AgentPrompt $prompt, Closure $next)
    {
        return $next($prompt)->then(function ($response) use ($prompt): void {
            try {
                $agentClass = $prompt->agent::class;
                $conversationId = $this->resolveConversationId($prompt);

                $this->trackingService->record(
                    usage: $response->usage,
                    meta: $response->meta,
                    agentClass: $agentClass,
                    conversationId: $conversationId,
                );
            } catch (Throwable $e) {
                Log::debug('Token tracking failed', ['error' => $e->getMessage()]);
            }
        });
    }

    private function resolveConversationId(AgentPrompt $prompt): ?int
    {
        $agent = $prompt->agent;

        if (method_exists($agent, 'currentConversation')) {
            $id = $agent->currentConversation();

            return $id !== null ? (int) $id : null;
        }

        return null;
    }
}
