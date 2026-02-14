<?php

namespace App\Livewire;

use App\Agent\AgentOrchestrator;
use App\Agent\StreamBuffer;
use App\Enums\MessageRole;
use App\Memory\ConversationService;
use App\Memory\MessageService;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class Chat extends Component
{
    public string $message = '';

    public ?int $conversationId = null;

    public bool $isThinking = false;

    public string $pendingMessage = '';

    public function mount(?int $conversationId = null): void
    {
        $this->conversationId = $conversationId;
    }

    public function sendMessage(): void
    {
        $text = trim($this->message);

        if ($text === '') {
            return;
        }

        if ($this->conversationId === null) {
            $conversation = app(ConversationService::class)->create('');
            $this->conversationId = $conversation->id;
            $this->dispatch('conversation-created', conversationId: $conversation->id);
        }

        $this->message = '';
        $this->pendingMessage = $text;
        $this->isThinking = true;
        $this->dispatch('agent-status-changed', state: 'thinking');

        $this->js('$wire.generateResponse()');
    }

    public function generateResponse(): void
    {
        if ($this->conversationId === null || $this->pendingMessage === '') {
            return;
        }

        $text = $this->pendingMessage;
        $this->pendingMessage = '';

        try {
            $orchestrator = app(AgentOrchestrator::class);
            $buffer = new StreamBuffer((string) $this->conversationId);

            $orchestrator->respondStreaming(
                message: $text,
                conversationId: $this->conversationId,
                buffer: $buffer,
                onChunk: function (string $chunk) {
                    $this->stream(to: 'streamedResponse', content: $chunk);
                },
            );
        } finally {
            $this->isThinking = false;
            $this->dispatch('agent-status-changed', state: 'idle');
            $this->dispatch('message-sent');
        }
    }

    #[On('conversation-selected')]
    public function onConversationSelected(int $conversationId): void
    {
        $this->conversationId = $conversationId > 0 ? $conversationId : null;
        $this->isThinking = false;
    }

    public function render()
    {
        $messages = collect();

        if ($this->conversationId !== null) {
            $messages = app(MessageService::class)->loadHistory($this->conversationId, 50);
        }

        return view('livewire.chat', [
            'messages' => $messages,
        ]);
    }

    public function renderMarkdown(string $content): string
    {
        return Str::markdown($content, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
