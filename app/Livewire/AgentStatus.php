<?php

namespace App\Livewire;

use App\Agent\ProviderManager;
use App\Memory\MessageService;
use Livewire\Attributes\On;
use Livewire\Component;

class AgentStatus extends Component
{
    public string $state = 'idle';

    public ?int $conversationId = null;

    public int $tokenCount = 0;

    public function mount(?int $conversationId = null): void
    {
        $this->conversationId = $conversationId;
        $this->refreshTokenCount();
    }

    #[On('agent-status-changed')]
    public function updateState(string $state): void
    {
        $this->state = $state;
    }

    #[On('conversation-selected')]
    public function onConversationSelected(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->refreshTokenCount();
    }

    #[On('message-sent')]
    public function onMessageSent(): void
    {
        $this->refreshTokenCount();
    }

    public function render()
    {
        $contextWindow = (int) config('aegis.agent.context_window', 200000);
        $contextUsagePercent = $contextWindow > 0
            ? min(100, (int) floor(($this->tokenCount / $contextWindow) * 100))
            : 0;
        $usageWidthClass = match (true) {
            $contextUsagePercent >= 100 => 'w-full',
            $contextUsagePercent >= 90 => 'w-11/12',
            $contextUsagePercent >= 80 => 'w-10/12',
            $contextUsagePercent >= 70 => 'w-9/12',
            $contextUsagePercent >= 60 => 'w-8/12',
            $contextUsagePercent >= 50 => 'w-6/12',
            $contextUsagePercent >= 40 => 'w-5/12',
            $contextUsagePercent >= 30 => 'w-4/12',
            $contextUsagePercent >= 20 => 'w-3/12',
            $contextUsagePercent >= 10 => 'w-2/12',
            $contextUsagePercent > 0 => 'w-1/12',
            default => 'w-0',
        };

        [$resolvedProvider, $resolvedModel] = app(ProviderManager::class)->resolve();

        return view('livewire.agent-status', [
            'provider' => $resolvedProvider,
            'model' => $resolvedModel,
            'contextWindow' => $contextWindow,
            'contextUsagePercent' => $contextUsagePercent,
            'usageWidthClass' => $usageWidthClass,
        ]);
    }

    private function refreshTokenCount(): void
    {
        if ($this->conversationId === null) {
            $this->tokenCount = 0;

            return;
        }

        $this->tokenCount = app(MessageService::class)->tokenCount($this->conversationId);
    }
}
