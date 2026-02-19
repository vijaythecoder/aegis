<?php

namespace App\Livewire;

use App\Memory\ConversationService;
use App\Models\Agent as AgentModel;
use App\Models\Conversation;
use App\Models\Project;
use Livewire\Attributes\On;
use Livewire\Component;

class ConversationSidebar extends Component
{
    public ?int $activeConversationId = null;

    public string $search = '';

    public bool $agentsOpen = true;

    public bool $conversationsOpen = true;

    public bool $projectsOpen = true;

    public function selectConversation(int $conversationId): void
    {
        $this->activeConversationId = $conversationId;

        if (! request()->routeIs('chat', 'chat.conversation')) {
            $this->redirect(route('chat.conversation', $conversationId), navigate: true);

            return;
        }

        $this->dispatch('conversation-selected', conversationId: $conversationId);
    }

    public function createConversation(): void
    {
        $service = app(ConversationService::class);
        $conversation = $service->create('');

        $this->activeConversationId = $conversation->id;

        if (! request()->routeIs('chat', 'chat.conversation')) {
            $this->redirect(route('chat.conversation', $conversation->id), navigate: true);

            return;
        }

        $this->dispatch('conversation-selected', conversationId: $conversation->id);
    }

    public function openAgentConversation(int $agentId): void
    {
        AgentModel::query()->where('is_active', true)->findOrFail($agentId);

        $conversation = Conversation::query()
            ->where('agent_id', $agentId)
            ->orderByDesc('last_message_at')
            ->first();

        if (! $conversation) {
            $service = app(ConversationService::class);
            $conversation = $service->create('');
            $conversation->update(['agent_id' => $agentId]);
        }

        $this->activeConversationId = $conversation->id;

        if (! request()->routeIs('chat', 'chat.conversation')) {
            $this->redirect(route('chat.conversation', $conversation->id), navigate: true);

            return;
        }

        $this->dispatch('conversation-selected', conversationId: $conversation->id);
    }

    public function deleteConversation(int $conversationId): void
    {
        $service = app(ConversationService::class);
        $service->delete($conversationId);

        if ($this->activeConversationId === $conversationId) {
            $this->activeConversationId = null;
            $this->dispatch('conversation-selected', conversationId: 0);
        }
    }

    #[On('conversation-created')]
    public function onConversationCreated(int $conversationId): void
    {
        $this->activeConversationId = $conversationId;
    }

    #[On('conversation-title-updated')]
    public function onTitleUpdated(): void
    {
        // Re-render triggers query refresh in render()
    }

    public function render()
    {
        $query = Conversation::query()
            ->whereNull('agent_id')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if (trim($this->search) !== '') {
            $query->where('title', 'like', '%'.trim($this->search).'%');
        }

        return view('livewire.conversation-sidebar', [
            'conversations' => $query->limit(50)->get(),
            'agents' => AgentModel::query()
                ->where('is_active', true)
                ->where('slug', '!=', 'aegis')
                ->orderBy('name')
                ->get(),
            'projects' => Project::query()
                ->where('status', 'active')
                ->withCount(['tasks', 'tasks as completed_tasks_count' => function ($q) {
                    $q->where('status', 'completed');
                }])
                ->orderByDesc('updated_at')
                ->limit(20)
                ->get(),
        ]);
    }
}
