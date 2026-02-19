<?php

namespace App\Agent\Middleware;

use App\Models\Task;
use App\Services\ProjectKnowledgeService;
use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Throwable;

class InjectProjectContext
{
    public function __construct(
        protected ProjectKnowledgeService $knowledgeService,
    ) {}

    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $context = $this->buildProjectContext($prompt);

        if ($context === '') {
            return $next($prompt);
        }

        return $next($prompt->prepend($context));
    }

    private function buildProjectContext(AgentPrompt $prompt): string
    {
        try {
            $agentId = $this->resolveAgentId($prompt);

            if ($agentId === null) {
                return '';
            }

            $tasks = Task::query()
                ->where('assigned_type', 'agent')
                ->where('assigned_id', $agentId)
                ->whereNotNull('project_id')
                ->whereIn('status', ['pending', 'in_progress'])
                ->with('project')
                ->limit(5)
                ->get();

            if ($tasks->isEmpty()) {
                return '';
            }

            $projectIds = $tasks->pluck('project_id')->unique();
            $lines = ["## Active Project Context\n"];

            foreach ($projectIds as $projectId) {
                $projectTasks = $tasks->where('project_id', $projectId);
                $project = $projectTasks->first()->project;

                if ($project === null) {
                    continue;
                }

                $lines[] = "### {$project->title}";

                if ($project->description) {
                    $lines[] = $project->description;
                }

                $taskList = $projectTasks->map(fn (Task $t): string => "- [{$t->status}] {$t->title}")->implode("\n");
                $lines[] = "\nYour assigned tasks:\n{$taskList}";

                $summary = $this->knowledgeService->summarize($projectId);

                if ($summary !== '') {
                    $lines[] = "\nProject knowledge:\n{$summary}";
                }
            }

            $context = implode("\n", $lines);

            if (mb_strlen($context) > 2000) {
                $context = mb_substr($context, 0, 2000)."\n\n[Project context truncated]";
            }

            return $context;
        } catch (Throwable $e) {
            Log::debug('Project context injection failed', ['error' => $e->getMessage()]);

            return '';
        }
    }

    private function resolveAgentId(AgentPrompt $prompt): ?int
    {
        if (property_exists($prompt, 'agent') && is_callable([$prompt->agent, 'agentModel'])) {
            return $prompt->agent->agentModel()->id;
        }

        return null;
    }
}
