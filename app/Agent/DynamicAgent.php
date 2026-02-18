<?php

namespace App\Agent;

use App\Agent\Middleware\InjectMemoryContext;
use App\Agent\Middleware\TrackTokenUsage;
use App\Memory\UserProfileService;
use App\Models\Agent as AgentModel;
use App\Tools\ToolRegistry;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Stringable;

#[MaxSteps(50)]
#[Timeout(120)]
class DynamicAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function __construct(
        private readonly AgentModel $agentModel,
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly ToolRegistry $toolRegistry,
        private readonly ProviderManager $providerManager,
        private readonly UserProfileService $userProfileService,
    ) {}

    public function forConversation(string|int $conversationId, bool $withStorage = true): static
    {
        if ($withStorage) {
            return $this->continue((string) $conversationId, new ConversationUser);
        }

        $this->conversationId = (string) $conversationId;
        $this->conversationUser = null;

        return $this;
    }

    public function instructions(): Stringable|string
    {
        $userProfile = $this->userProfileService->getProfile();
        $basePrompt = $this->promptBuilder->build(userProfile: $userProfile, agentModel: $this->agentModel);

        return "## Agent Identity\n{$this->agentModel->persona}\n\n{$basePrompt}";
    }

    public function provider(): string|array
    {
        $provider = $this->agentModel->provider;

        if ($provider !== null && $provider !== '') {
            if (config('aegis.agent.failover_enabled', true)) {
                return $this->failoverChain($provider);
            }

            return $provider;
        }

        if (config('aegis.agent.failover_enabled', true)) {
            return $this->failoverChain();
        }

        return $this->resolvedProvider()[0];
    }

    public function model(): string
    {
        if ($this->agentModel->model !== null && $this->agentModel->model !== '') {
            return $this->agentModel->model;
        }

        return $this->resolvedProvider()[1];
    }

    public function timeout(): int
    {
        return (int) config('aegis.agent.timeout', 120);
    }

    public function tools(): iterable
    {
        $allTools = collect($this->toolRegistry->all())
            ->filter(fn (mixed $tool): bool => $tool instanceof Tool || $tool instanceof ProviderTool)
            ->values();

        $allowedToolClasses = $this->agentModel->tools()->pluck('tool_class')->all();

        if ($allowedToolClasses !== []) {
            return $allTools
                ->filter(fn (mixed $tool): bool => in_array(get_class($tool), $allowedToolClasses, true))
                ->values()
                ->all();
        }

        return $allTools->all();
    }

    public function middleware(): array
    {
        return [
            app(InjectMemoryContext::class),
            app(TrackTokenUsage::class),
        ];
    }

    protected function maxConversationMessages(): int
    {
        return (int) config('aegis.memory.max_conversation_messages', 100);
    }

    public function agentModel(): AgentModel
    {
        return $this->agentModel;
    }

    private function failoverChain(?string $primaryProvider = null): array
    {
        [$primary, $model] = $this->providerManager->resolve($primaryProvider, $this->agentModel->model);

        $chain = [$primary => $model];

        foreach (config('aegis.failover_chain', []) as $fallback) {
            if (is_string($fallback) && $fallback !== '' && $fallback !== $primary) {
                $chain[$fallback] = null;
            }
        }

        return $chain;
    }

    private function resolvedProvider(): array
    {
        return $this->providerManager->resolve(
            $this->agentModel->provider,
            $this->agentModel->model
        );
    }
}
