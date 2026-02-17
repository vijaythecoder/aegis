<?php

namespace App\Services;

use App\Models\TokenUsage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class TokenTrackingService
{
    public function __construct(private readonly CostEstimator $costEstimator) {}

    public function record(
        Usage $usage,
        Meta $meta,
        ?string $agentClass = null,
        ?int $conversationId = null,
        ?int $messageId = null,
    ): TokenUsage {
        $provider = $meta->provider ?? 'unknown';
        $model = $meta->model ?? 'unknown';

        $promptTokens = $usage->promptTokens;
        $completionTokens = $usage->completionTokens;
        $cacheReadTokens = $usage->cacheReadInputTokens;
        $cacheWriteTokens = $usage->cacheWriteInputTokens;
        $reasoningTokens = $usage->reasoningTokens;
        $totalTokens = $promptTokens + $completionTokens;

        $costData = $this->costEstimator->estimate(
            provider: $provider,
            model: $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            cacheReadTokens: $cacheReadTokens,
            cacheWriteTokens: $cacheWriteTokens,
            reasoningTokens: $reasoningTokens,
        );

        return TokenUsage::query()->create([
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'agent_class' => $agentClass,
            'provider' => $provider,
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'cache_write_tokens' => $cacheWriteTokens,
            'reasoning_tokens' => $reasoningTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost' => $costData['estimated_cost'],
            'currency' => $costData['currency'],
        ]);
    }

    public function recordFromResponse(
        AgentResponse $response,
        ?string $agentClass = null,
        ?int $conversationId = null,
        ?int $messageId = null,
    ): TokenUsage {
        return $this->record(
            usage: $response->usage,
            meta: $response->meta,
            agentClass: $agentClass,
            conversationId: $conversationId,
            messageId: $messageId,
        );
    }
}
