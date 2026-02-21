<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Skill;
use Illuminate\Support\Collection;

class SkillSuggestionService
{
    /** @var array<string, list<string>> */
    private const KEYWORD_MAP = [
        'research-assistant' => ['research', 'investigate', 'analyze', 'find information', 'look up', 'explore', 'study', 'discover'],
        'writing-coach' => ['write', 'writing', 'edit', 'draft', 'content', 'blog', 'article', 'essay', 'email', 'copywriting', 'proofread'],
        'data-analyst' => ['data', 'analytics', 'statistics', 'spreadsheet', 'numbers', 'metrics', 'dashboard', 'chart', 'report', 'csv'],
        'schedule-manager' => ['schedule', 'calendar', 'appointment', 'meeting', 'deadline', 'time management', 'planner', 'organize'],
        'finance-tracker' => ['finance', 'budget', 'money', 'expense', 'tax', 'accounting', 'investment', 'savings', 'income', 'financial'],
        'health-fitness' => ['fitness', 'workout', 'exercise', 'health', 'nutrition', 'diet', 'gym', 'training', 'wellness', 'weight', 'yoga', 'running'],
        'learning-guide' => ['learn', 'study', 'education', 'teach', 'tutor', 'course', 'exam', 'homework', 'school', 'university', 'knowledge'],
    ];

    /** @var array<string, list<string>> */
    private const CATEGORY_MAP = [
        'finance' => ['finance-tracker', 'data-analyst'],
        'health' => ['health-fitness', 'schedule-manager'],
        'education' => ['learning-guide', 'research-assistant', 'writing-coach'],
        'productivity' => ['schedule-manager', 'research-assistant'],
        'work' => ['schedule-manager', 'writing-coach', 'data-analyst'],
        'personal' => ['schedule-manager', 'health-fitness'],
    ];

    public function suggestForPersona(string $persona): Collection
    {
        $lower = mb_strtolower($persona);
        $matchedSlugs = [];

        foreach (self::KEYWORD_MAP as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $matchedSlugs[$slug] = true;

                    break;
                }
            }
        }

        if ($matchedSlugs === []) {
            return collect();
        }

        return Skill::query()
            ->whereIn('slug', array_keys($matchedSlugs))
            ->where('is_active', true)
            ->get();
    }

    public function suggestForProject(Project $project): Collection
    {
        $category = mb_strtolower($project->category ?? '');
        $slugs = self::CATEGORY_MAP[$category] ?? [];

        if ($slugs === []) {
            $combined = ($project->title ?? '').' '.($project->description ?? '');

            return $this->suggestForPersona($combined);
        }

        return Skill::query()
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->get();
    }
}
