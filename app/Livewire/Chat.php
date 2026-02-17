<?php

namespace App\Livewire;

use App\Agent\AegisAgent;
use App\Agent\AgentLoop;
use App\Agent\ModelCapabilities;
use App\Agent\ProviderManager;
use App\Enums\MessageRole;
use App\Jobs\ExtractMemoriesJob;
use App\Memory\ConversationService;
use App\Memory\MessageService;
use App\Models\Conversation;
use App\Security\ApiKeyManager;
use Illuminate\Support\Str;
use Laravel\Ai\Streaming\Events\TextDelta;
use Livewire\Attributes\On;
use Livewire\Component;

class Chat extends Component
{
    public string $message = '';

    public ?int $conversationId = null;

    public bool $isThinking = false;

    public string $pendingMessage = '';

    public string $selectedProvider = '';

    public string $selectedModel = '';

    public function mount(?int $conversationId = null): void
    {
        $this->conversationId = $conversationId;
        $this->loadModelSelection();
    }

    public function updatedSelectedProvider(): void
    {
        $models = app(ModelCapabilities::class)->modelsForProvider($this->selectedProvider);
        $this->selectedModel = $models[0] ?? app(ModelCapabilities::class)->defaultModel($this->selectedProvider);
        $this->saveModelToConversation();
    }

    public function updatedSelectedModel(): void
    {
        $this->saveModelToConversation();
    }

    public function sendMessage(): void
    {
        $text = trim($this->message);

        if ($text === '') {
            return;
        }

        if ($this->conversationId === null) {
            $conversation = app(ConversationService::class)->create(
                '',
                $this->selectedModel ?: null,
                $this->selectedProvider ?: null,
            );
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
        set_time_limit((int) config('aegis.agent.timeout', 300));

        if ($this->conversationId === null || $this->pendingMessage === '') {
            return;
        }

        $text = $this->pendingMessage;
        $this->pendingMessage = '';

        try {
            $agent = app(AegisAgent::class);
            $agent->forConversation($this->conversationId);

            if ($this->selectedProvider !== '') {
                $agent->withProvider($this->selectedProvider);
            }
            if ($this->selectedModel !== '') {
                $agent->withModel($this->selectedModel);
            }

            $loop = app(AgentLoop::class);

            $loop->onStep(function (string $phase, string $detail): void {
                $this->dispatch('agent-status-changed', state: $phase, detail: $detail);
            });

            if ($loop->requiresPlanning($text)) {
                $this->generatePlannedResponse($loop, $text);
            } else {
                $this->generateStreamedResponse($text);
            }

            $this->generateTitleIfNeeded($text);
        } finally {
            $this->isThinking = false;
            $this->dispatch('agent-status-changed', state: 'idle');
            $this->dispatch('message-sent');
        }
    }

    private function generateStreamedResponse(string $text): void
    {
        $agent = app(AegisAgent::class);
        $stream = $agent->stream($text);

        $assistantResponse = '';

        foreach ($stream as $event) {
            if ($event instanceof TextDelta) {
                $assistantResponse .= $event->delta;
                $this->stream(to: 'streamedResponse', content: $event->delta);
            }
        }

        if (trim($assistantResponse) !== '') {
            ExtractMemoriesJob::dispatch($text, $assistantResponse, $this->conversationId);
        }
    }

    private function generatePlannedResponse(AgentLoop $loop, string $text): void
    {
        $result = $loop->execute($text, $this->conversationId, withStorage: false);

        $messageService = app(MessageService::class);
        $messageService->store($this->conversationId, MessageRole::User, $text);
        $messageService->store($this->conversationId, MessageRole::Assistant, $result->response);

        $this->stream(to: 'streamedResponse', content: $result->response);

        if (trim($result->response) !== '') {
            ExtractMemoriesJob::dispatch($text, $result->response, $this->conversationId);
        }
    }

    private function generateTitleIfNeeded(string $userMessage): void
    {
        if ($this->conversationId === null) {
            return;
        }

        $conversation = \App\Models\Conversation::query()->find($this->conversationId);

        if (! $conversation || trim((string) $conversation->title) !== '') {
            return;
        }

        app(ConversationService::class)->generateTitle($this->conversationId, $userMessage);
        $this->dispatch('conversation-title-updated');
    }

    #[On('conversation-selected')]
    public function onConversationSelected(int $conversationId): void
    {
        $this->conversationId = $conversationId > 0 ? $conversationId : null;
        $this->isThinking = false;
        $this->loadModelSelection();
    }

    public function render()
    {
        $messages = collect();

        if ($this->conversationId !== null) {
            $messages = app(MessageService::class)->loadHistory($this->conversationId, 50);
        }

        return view('livewire.chat', [
            'messages' => $messages,
            'availableProviders' => $this->getAvailableProviders(),
            'availableModels' => $this->getAvailableModels(),
        ]);
    }

    private function loadModelSelection(): void
    {
        if ($this->conversationId !== null) {
            $conversation = Conversation::query()->find($this->conversationId);

            if ($conversation instanceof Conversation && $conversation->provider) {
                $this->selectedProvider = $conversation->provider;
                $this->selectedModel = $conversation->model ?? '';

                return;
            }
        }

        [$defaultProvider, $defaultModel] = app(ProviderManager::class)->resolve();
        $this->selectedProvider = $defaultProvider;
        $this->selectedModel = $defaultModel;
    }

    private function saveModelToConversation(): void
    {
        if ($this->conversationId === null) {
            return;
        }

        Conversation::query()
            ->where('id', $this->conversationId)
            ->update([
                'provider' => $this->selectedProvider,
                'model' => $this->selectedModel,
            ]);

        $this->dispatch('model-changed', provider: $this->selectedProvider, model: $this->selectedModel);
    }

    private function getAvailableProviders(): array
    {
        $list = app(ApiKeyManager::class)->list();
        $configured = [];

        foreach ($list as $id => $info) {
            if ($info['is_set'] || ! $info['requires_key']) {
                $configured[$id] = $info['name'];
            }
        }

        return $configured;
    }

    private function getAvailableModels(): array
    {
        if ($this->selectedProvider === '') {
            return [];
        }

        return app(ModelCapabilities::class)->modelsForProvider($this->selectedProvider);
    }

    public function renderMarkdown(string $content): string
    {
        return Str::markdown($content, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
