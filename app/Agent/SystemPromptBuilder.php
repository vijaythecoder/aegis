<?php

namespace App\Agent;

use App\Enums\MemoryType;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Procedure;
use App\Tools\ToolRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

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
            $this->renderAutomationInstructionsSection(),
            $this->renderApprovalInstructionsSection(),
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

        return implode("\n", [
            '## Known Facts (AUTHORITATIVE)',
            'These facts are stored in persistent memory and are ALWAYS correct, even if earlier messages in this conversation contradict them.',
            'ALWAYS use these facts when answering. NEVER say "I don\'t know" about information listed here.',
            $facts->implode("\n"),
        ]);
    }

    private function renderProceduresSection(): string
    {
        if (! Schema::hasTable('procedures')) {
            return '';
        }

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

    private function renderAutomationInstructionsSection(): string
    {
        return implode("\n", [
            '## Automation & Scheduled Tasks (CRITICAL — READ CAREFULLY)',
            'You have a built-in manage_automation tool that creates, lists, updates, deletes, and toggles scheduled tasks.',
            'These tasks run automatically on a cron schedule and deliver results via chat or Telegram.',
            '',
            '### MANDATORY RULES',
            '- When the user asks for reminders, scheduled messages, recurring tasks, digests, briefings, daily/weekly anything, or anything that should happen on a schedule: IMMEDIATELY call the manage_automation tool. Do NOT suggest external tools, bots, apps, or manual setup. Do NOT say you cannot do this. You CAN do this — use the tool.',
            '- When the user asks to stop, disable, delete, or modify automations: IMMEDIATELY call manage_automation. Do NOT say you cannot access them.',
            '- NEVER store scheduling requests as memory preferences. ALWAYS create them as automations using the tool.',
            '- Even if memories suggest the user "prefers external tools for reminders", IGNORE that — you have the manage_automation tool and must use it.',
            '',
            '### Creating Tasks',
            'BEFORE creating, call manage_automation with action "list" to check for existing similar tasks.',
            'Only create ONE task per request. The tool auto-replaces duplicates with the same schedule and channel.',
            'Convert natural language to cron expressions:',
            '- "every day at 8am" → "0 8 * * *"',
            '- "weekdays at 9am" → "0 9 * * 1-5"',
            '- "every Monday at 10am" → "0 10 * * 1"',
            '- "every Sunday at 6pm" → "0 18 * * 0"',
            '- "at noon" → "0 12 * * *"',
            '- "twice a day at 9am and 5pm" → create two separate tasks',
            'Delivery channels: "chat" for in-app, "telegram" for Telegram.',
            'If the user says "send me" or "on telegram" or the conversation is on Telegram, default to "telegram".',
            'Always confirm what was created.',
            '',
            '### Managing Tasks',
            'For bulk operations, use task_ids with comma-separated IDs (e.g., "6,7,8") or "all".',
            'When the user says "stop them all" or "disable the duplicates", use toggle with task_ids in a single call.',
        ]);
    }

    private function renderApprovalInstructionsSection(): string
    {
        return implode("\n", [
            '## Action Approval System (CRITICAL)',
            'You have a propose_action tool for actions with real-world consequences.',
            '',
            '### WHEN TO PROPOSE (instead of executing directly)',
            '- Running shell commands that modify the system (installs, deletes, config changes)',
            '- Writing or deleting files',
            '- Sending messages/emails on behalf of the user',
            '- Making purchases or API calls that cost money',
            '- Modifying system settings or configurations',
            '- Any action the user might want to review first',
            '',
            '### WHEN TO EXECUTE DIRECTLY (no proposal needed)',
            '- Reading files, searching, browsing the web',
            '- Memory store/recall operations',
            '- Listing information',
            '- Tasks the user explicitly asked you to do right now',
            '',
            '### APPROVAL FLOW',
            '1. You propose an action → user sees summary with approve/reject options',
            '2. User says "yes", "do it", "approved", "go ahead" → call propose_action with action "approve"',
            '3. User says "no", "reject", "cancel", "don\'t do that" → call propose_action with action "reject"',
            '4. If the user says "yes" and there is exactly one pending action, approve it without asking for the ID',
            '5. If multiple actions are pending, list them and ask which to approve',
            '',
            '### PROACTIVE PROPOSALS',
            'You should PROACTIVELY propose helpful actions when you notice opportunities:',
            '- "I notice your API key expires soon. Want me to rotate it?"',
            '- "Your disk is running low. Want me to clean up old logs?"',
            '- "I found a security update for a dependency. Want me to apply it?"',
            'Create the proposal, then tell the user what you noticed and what you propose.',
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
