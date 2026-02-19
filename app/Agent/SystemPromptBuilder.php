<?php

namespace App\Agent;

use App\Enums\MemoryType;
use App\Models\Agent as AgentModel;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Procedure;
use App\Models\Project;
use App\Models\Skill;
use App\Tools\ToolRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SystemPromptBuilder
{
    public function __construct(private readonly ToolRegistry $toolRegistry) {}

    public function build(?Conversation $conversation = null, ?string $userProfile = null, ?AgentModel $agentModel = null): string
    {
        $appName = (string) config('aegis.name', 'Aegis');
        $tagline = (string) config('aegis.tagline', 'AI under your Aegis');
        $timestamp = now()->toDateTimeString();

        $sections = [
            "You are {$appName}, {$tagline}.",
            'Be concise, safe, and accurate. Use tools when they improve the answer.',
            "Current datetime: {$timestamp}",
            $this->renderSkillsSection($agentModel),
            $this->renderAgentsSection(),
            $this->renderProjectsSection(),
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

    private function renderSkillsSection(?AgentModel $agentModel): string
    {
        if ($agentModel === null) {
            return '';
        }

        $skills = $agentModel->skills()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Skill $skill): bool => trim($skill->instructions) !== '');

        if ($skills->isEmpty()) {
            return '';
        }

        $entries = $skills->map(function (Skill $skill): string {
            $instructions = $skill->instructions;
            // Warn if skill instructions exceed ~3000 tokens (~12000 chars)
            if (mb_strlen($instructions) > 12000) {
                Log::warning('aegis.skill.oversized', [
                    'skill' => $skill->slug,
                    'chars' => mb_strlen($instructions),
                ]);
                $instructions = mb_substr($instructions, 0, 12000)."\n\n[Truncated — skill content exceeds recommended length]";
            }

            return "### {$skill->name}\n{$instructions}";
        });

        return "## Specialized Knowledge\n\n".$entries->implode("\n\n");
    }

    private function renderAgentsSection(): string
    {
        if (! Schema::hasTable('agents')) {
            return '';
        }

        $agents = AgentModel::query()
            ->where('is_active', true)
            ->where('slug', '!=', 'aegis')
            ->limit(10)
            ->get();

        if ($agents->isEmpty()) {
            return '';
        }

        $lines = $agents->map(function (AgentModel $agent): string {
            $skills = $agent->skills()->where('is_active', true)->pluck('name')->implode(', ');
            $skillInfo = $skills !== '' ? " (skills: {$skills})" : '';

            return "- {$agent->name} ({$agent->slug}): {$agent->persona}{$skillInfo}";
        });

        return "## Available Agents\n"
            ."These are specialized AI agents the user has created. You can suggest using them for relevant tasks.\n"
            ."Use manage_tasks to assign tasks to agents by their slug.\n"
            .$lines->implode("\n");
    }

    private function renderProjectsSection(): string
    {
        if (! Schema::hasTable('projects')) {
            return '';
        }

        $projects = Project::query()
            ->whereIn('status', ['active', 'paused'])
            ->withCount([
                'tasks as pending_tasks_count' => function ($q): void {
                    $q->where('status', 'pending');
                },
            ])
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        if ($projects->isEmpty()) {
            return '';
        }

        $lines = $projects->map(function (Project $project): string {
            $status = $project->status !== 'active' ? " [{$project->status}]" : '';
            $pending = $project->pending_tasks_count > 0 ? " — {$project->pending_tasks_count} pending tasks" : '';
            $deadline = $project->deadline ? ' (due: '.$project->deadline->format('M j, Y').')' : '';

            return "- {$project->title}{$status}{$pending}{$deadline}";
        });

        return "## Active Projects\n"
            ."The user has these active projects. Use manage_projects and manage_tasks tools to help manage them.\n"
            .$lines->implode("\n");
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
            return "User preferences:\n- none";
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
            '## Memory System (Automatic)',
            'You have a persistent memory system that works automatically across all conversations.',
            'Relevant memories are auto-recalled and injected before each message — you do NOT need to search manually.',
            'Important facts, preferences, and notes from conversations are automatically extracted and stored in the background after each exchange.',
            'If the user corrects a stored fact (e.g., "my name is actually X"), acknowledge the correction — it will be updated automatically.',
            'Focus on answering the user\'s question using any recalled memories shown above.',
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
