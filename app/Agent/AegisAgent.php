<?php

namespace App\Agent;

use App\Agent\Middleware\InjectMemoryContext;
use App\Agent\Middleware\TrackTokenUsage;
use App\Memory\UserProfileService;
use App\Models\Setting;
use App\Tools\KnowledgeSearchTool;
use App\Tools\ToolRegistry;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Stringable;

#[MaxSteps(10)]
#[Timeout(120)]
class AegisAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;
    use RemembersConversations;

    private ?string $overrideProvider = null;

    private ?string $overrideModel = null;

    public function __construct(
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly ToolRegistry $toolRegistry,
        private readonly ProviderManager $providerManager,
        private readonly UserProfileService $userProfileService,
    ) {}

    public function forConversation(string|int $conversationId, bool $withStorage = true): static
    {
        $this->overrideProvider = null;
        $this->overrideModel = null;

        if ($withStorage) {
            return $this->continue((string) $conversationId, new ConversationUser);
        }

        $this->conversationId = (string) $conversationId;

        return $this;
    }

    public function withProvider(?string $provider): static
    {
        $this->overrideProvider = $provider;

        return $this;
    }

    public function withModel(?string $model): static
    {
        $this->overrideModel = $model;

        return $this;
    }

    public function instructions(): Stringable|string
    {
        $userProfile = $this->userProfileService->getProfile();

        return $this->promptBuilder->build(userProfile: $userProfile);
    }

    public function provider(): string|array
    {
        if (config('aegis.agent.failover_enabled', true)) {
            return $this->failoverChain();
        }

        return $this->resolvedProvider()[0];
    }

    public function model(): string
    {
        $role = $this->modelRole();

        if ($role !== 'default') {
            $providerName = $this->resolvedProvider()[0];
            $sdkProvider = Ai::textProviderFor($this, $providerName);

            return match ($role) {
                'smartest' => $sdkProvider->smartestTextModel(),
                'cheapest' => $sdkProvider->cheapestTextModel(),
                default => $this->resolvedProvider()[1],
            };
        }

        return $this->resolvedProvider()[1];
    }

    private function modelRole(): string
    {
        return Setting::query()
            ->where('group', 'agent')
            ->where('key', 'model_role')
            ->value('value') ?? 'default';
    }

    /**
     * @return array<string, string|null>
     */
    private function failoverChain(): array
    {
        [$primary, $model] = $this->resolvedProvider();

        $chain = [$primary => $model];

        foreach (config('aegis.failover_chain', []) as $fallback) {
            if (is_string($fallback) && $fallback !== '' && $fallback !== $primary) {
                $chain[$fallback] = null;
            }
        }

        return $chain;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolvedProvider(): array
    {
        return $this->providerManager->resolve($this->overrideProvider, $this->overrideModel);
    }

    public function timeout(): int
    {
        return (int) config('aegis.agent.timeout', 120);
    }

    /**
     * Providers that act as proxies and don't natively support FileSearch.
     *
     * @var list<string>
     */
    private const array FILE_SEARCH_EXCLUDED_PROVIDERS = [
        'openrouter',
        'groq',
        'deepseek',
        'ollama',
        'mistral',
        'xai',
    ];

    public function tools(): iterable
    {
        $tools = collect($this->toolRegistry->all())
            ->filter(fn (mixed $tool): bool => $tool instanceof Tool || $tool instanceof ProviderTool)
            ->values()
            ->all();

        $storeIds = KnowledgeSearchTool::vectorStoreIds();

        if ($storeIds !== []) {
            $providerName = $this->resolvedProvider()[0];

            if (! in_array($providerName, self::FILE_SEARCH_EXCLUDED_PROVIDERS, true)) {
                $provider = Ai::textProviderFor($this, $providerName);

                if ($provider instanceof SupportsFileSearch) {
                    $tools[] = new FileSearch($storeIds);
                }
            }
        }

        return $tools;
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
}
