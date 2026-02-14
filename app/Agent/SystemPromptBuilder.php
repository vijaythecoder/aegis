<?php

namespace App\Agent;

use App\Agent\Contracts\ToolInterface;
use App\Enums\MemoryType;
use App\Models\Conversation;
use App\Models\Memory;
use Illuminate\Support\Collection;

class SystemPromptBuilder
{
    public function __construct(private iterable $tools = []) {}

    public function build(?Conversation $conversation = null): string
    {
        $appName = (string) config('aegis.name', 'Aegis');
        $tagline = (string) config('aegis.tagline', 'AI under your Aegis');
        $timestamp = now()->toDateTimeString();

        $sections = [
            "You are {$appName}, {$tagline}.",
            'Be concise, safe, and accurate. Use tools when they improve the answer.',
            "Current datetime: {$timestamp}",
            $this->renderToolsSection(),
            $this->renderPreferencesSection($conversation),
        ];

        return implode("\n\n", array_filter($sections));
    }

    private function renderToolsSection(): string
    {
        $tools = collect($this->tools)
            ->map(fn (ToolInterface $tool): string => "- {$tool->name()}: {$tool->description()}")
            ->values();

        if ($tools->isEmpty()) {
            return 'Available tools:\n- none';
        }

        return "Available tools:\n{$tools->implode("\n")}";
    }

    private function renderPreferencesSection(?Conversation $conversation): string
    {
        $preferences = $this->preferences($conversation)
            ->map(fn (Memory $memory): string => "- {$memory->key}: {$memory->value}")
            ->values();

        if ($preferences->isEmpty()) {
            return 'User preferences:\n- none';
        }

        return "User preferences:\n{$preferences->implode("\n")}";
    }

    private function preferences(?Conversation $conversation): Collection
    {
        return Memory::query()
            ->where('type', MemoryType::Preference)
            ->when(
                $conversation,
                fn ($query) => $query->where(function ($subQuery) use ($conversation): void {
                    $subQuery->whereNull('conversation_id')
                        ->orWhere('conversation_id', $conversation->id);
                }),
                fn ($query) => $query->whereNull('conversation_id')
            )
            ->orderByDesc('confidence')
            ->limit(10)
            ->get();
    }
}
