<?php

namespace App\Livewire;

use App\Memory\ConversationService;
use App\Models\Conversation;
use Livewire\Attributes\On;
use Livewire\Component;

class ConversationSidebar extends Component
{
    public ?int $activeConversationId = null;

    public string $search = '';

    public function selectConversation(int $conversationId): void
    {
        $this->activeConversationId = $conversationId;
        $this->dispatch('conversation-selected', conversationId: $conversationId);
    }

    public function createConversation(): void
    {
        $service = app(ConversationService::class);
        $conversation = $service->create('');

        $this->activeConversationId = $conversation->id;
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

    public function render()
    {
        $query = Conversation::query()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if (trim($this->search) !== '') {
            $query->where('title', 'like', '%' . trim($this->search) . '%');
        }

        return view('livewire.conversation-sidebar', [
            'conversations' => $query->limit(50)->get(),
        ]);
    }
}
