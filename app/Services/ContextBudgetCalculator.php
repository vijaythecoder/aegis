<?php

namespace App\Services;

use App\Agent\ModelCapabilities;
use App\Agent\ProviderManager;
use App\Agent\SystemPromptBuilder;
use App\Models\Agent;
use Illuminate\Support\Facades\Log;

class ContextBudgetCalculator
{
    private const CHARS_PER_TOKEN = 4;

    private const WARNING_THRESHOLD = 0.30;

    public function __construct(
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly ModelCapabilities $modelCapabilities,
        private readonly ProviderManager $providerManager,
    ) {}

    /**
     * Calculate token budget breakdown for an agent.
     *
     * @return array{base_prompt: int, skills: int, project_context: int, total: int, model_limit: int, remaining_for_conversation: int, warning: string|null}
     */
    public function calculate(Agent $agentModel): array
    {
        $basePromptTokens = $this->estimateBasePrompt();
        $skillTokens = $this->estimateSkills($agentModel);
        $projectContextTokens = $this->estimateProjectContext();

        $total = $basePromptTokens + $skillTokens + $projectContextTokens;
        $modelLimit = $this->resolveModelLimit($agentModel);
        $remaining = max(0, $modelLimit - $total);

        $warning = null;

        if ($modelLimit > 0 && ($total / $modelLimit) > self::WARNING_THRESHOLD) {
            $pct = round(($total / $modelLimit) * 100);
            $warning = "System prompt uses ~{$pct}% of model context window ({$total} / {$modelLimit} tokens). Consider removing skills to leave room for conversation.";

            Log::warning('aegis.context_budget.exceeded_threshold', [
                'agent' => $agentModel->slug,
                'total_tokens' => $total,
                'model_limit' => $modelLimit,
                'percentage' => $pct,
            ]);
        }

        return [
            'base_prompt' => $basePromptTokens,
            'skills' => $skillTokens,
            'project_context' => $projectContextTokens,
            'total' => $total,
            'model_limit' => $modelLimit,
            'remaining_for_conversation' => $remaining,
            'warning' => $warning,
        ];
    }

    private function estimateBasePrompt(): int
    {
        $prompt = $this->promptBuilder->build();

        return $this->charsToTokens(mb_strlen($prompt));
    }

    private function estimateSkills(Agent $agentModel): int
    {
        $totalChars = $agentModel->skills()
            ->where('is_active', true)
            ->get()
            ->sum(fn ($skill) => mb_strlen($skill->instructions ?? '') + mb_strlen($skill->name ?? '') + 10);

        return $this->charsToTokens($totalChars);
    }

    private function estimateProjectContext(): int
    {
        return $this->charsToTokens(2000);
    }

    private function resolveModelLimit(Agent $agentModel): int
    {
        $provider = $agentModel->provider;
        $model = $agentModel->model;

        if ($provider !== null && $model !== null) {
            return $this->modelCapabilities->contextWindow($provider, $model);
        }

        [$defaultProvider, $defaultModel] = $this->providerManager->resolve();

        return $this->modelCapabilities->contextWindow($defaultProvider, $defaultModel);
    }

    private function charsToTokens(int $chars): int
    {
        return (int) ceil($chars / self::CHARS_PER_TOKEN);
    }
}
