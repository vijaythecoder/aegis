<?php

namespace App\Agent;

use App\Enums\MemoryType;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Procedure;
use App\Tools\ToolRegistry;
use Illuminate\Support\Collection;

class SystemPromptBuilder
{
    public function __construct(private readonly ToolRegistry $toolRegistry) {}

    public function build(?Conversation $conversation = null, ?string $userProfile = null): string
    {
        $appName = (string) config('aegis.name', 'Aegis');
        $tagline = (string) config('aegis.tagline', 'AI under your Aegis');
        $timestamp = now()->toDateTimeString();

        $sections = [
            "You are {$appName}, {$tagline}.",
            'Be concise, safe, and accurate. Use tools when they improve the answer.',
            "Current datetime: {$timestamp}",
            $this->renderToolsSection(),
            $this->renderUserProfileSection($userProfile),
            $this->renderPreferencesSection($conversation),
            $this->renderFactsSection($conversation),
            $this->renderProceduresSection(),
            $this->renderMemoryInstructionsSection(),
        ];

        return implode("\n\n", array_filter($sections));
    }

    private function renderToolsSection(): string
    {
        $tools = collect($this->toolRegistry->all())
            ->filter(fn (mixed $tool): bool => method_exists($tool, 'name') && method_exists($tool, 'description'))
            ->map(fn (mixed $tool): string => "- {$tool->name()}: {$tool->description()}")
            ->values();

        if ($tools->isEmpty()) {
            return 'Available tools:\n- none';
        }

        return "Available tools:\n{$tools->implode("\n")}";
    }

    private function renderUserProfileSection(?string $userProfile): string
    {
        if ($userProfile === null || trim($userProfile) === '') {
            return '';
        }

        return "## About This User\n{$userProfile}";
    }

    private function renderPreferencesSection(?Conversation $conversation): string
    {
        $preferences = $this->preferences($conversation)
            ->map(fn (Memory $memory): string => "- {$memory->key}: {$memory->value}")
            ->values();

        if ($preferences->isEmpty()) {
            return '';
        }

        return "User preferences:\n{$preferences->implode("\n")}";
    }

    private function renderFactsSection(?Conversation $conversation): string
    {
        $facts = $this->facts($conversation)
            ->map(fn (Memory $memory): string => "- {$memory->key}: {$memory->value}")
            ->values();

        if ($facts->isEmpty()) {
            return '';
        }

        return "Known facts about the user:\n{$facts->implode("\n")}";
    }

    private function renderProceduresSection(): string
    {
        $procedures = Procedure::query()
            ->where('is_active', true)
            ->orderBy('trigger')
            ->limit(20)
            ->get();

        if ($procedures->isEmpty()) {
            return '';
        }

        $lines = $procedures->map(fn (Procedure $p): string => "- When: {$p->trigger} → {$p->instruction}")
            ->implode("\n");

        return "## Learned Behaviors\nFollow these learned rules:\n{$lines}";
    }

    private function renderMemoryInstructionsSection(): string
    {
        return implode("\n", [
            '## Memory Recall',
            'You have access to a persistent memory system across all conversations.',
            'MANDATORY: Before answering any question that might reference past context, use the memory_recall tool to search for relevant information.',
            'MANDATORY: When the user shares important personal info, preferences, or project details, use the memory_store tool to save it.',
            'Examples of when to search: "like we discussed", "remember when", "my project", "as I mentioned", or any question about the user\'s background.',
            'Examples of when to store: user shares their name, job, tech stack, project details, preferences, or corrections.',
        ]);
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

    private function facts(?Conversation $conversation): Collection
    {
        return Memory::query()
            ->where('type', MemoryType::Fact)
            ->when(
                $conversation,
                fn ($query) => $query->where(function ($subQuery) use ($conversation): void {
                    $subQuery->whereNull('conversation_id')
                        ->orWhere('conversation_id', $conversation->id);
                }),
                fn ($query) => $query->whereNull('conversation_id')
            )
            ->orderByDesc('confidence')
            ->limit(15)
            ->get();
    }
}
