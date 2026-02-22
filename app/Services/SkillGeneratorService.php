<?php

namespace App\Services;

use App\Agent\SkillContentAgent;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SkillGeneratorService
{
    private const OCCURRENCE_THRESHOLD = 5;

    private const MAX_SKILL_TOKENS = 3000;

    /**
     * Detect recurring topic patterns from stored memories.
     *
     * Cheap operation — no LLM calls. Groups memory keys by keyword
     * and returns topics that exceed the occurrence threshold.
     *
     * @return array<string, int> topic => count
     */
    public function detectPatterns(): array
    {
        $memories = Memory::query()
            ->select(['key', 'value', 'type'])
            ->get();

        if ($memories->isEmpty()) {
            return [];
        }

        $topicCounts = [];

        foreach ($memories as $memory) {
            $keywords = $this->extractKeywords($memory->key, $memory->value);

            foreach ($keywords as $keyword) {
                $topicCounts[$keyword] = ($topicCounts[$keyword] ?? 0) + 1;
            }
        }

        // Filter to topics that meet the threshold and don't already have a skill
        $existingSlugs = Skill::query()->pluck('slug')->toArray();

        return collect($topicCounts)
            ->filter(fn (int $count) => $count >= self::OCCURRENCE_THRESHOLD)
            ->filter(fn (int $count, string $topic) => ! in_array(Str::slug($topic), $existingSlugs, true))
            ->sortDesc()
            ->toArray();
    }

    /**
     * Generate skill content for a given topic using AI.
     *
     * Gathers relevant memories and recent messages about the topic,
     * then uses the SkillContentAgent to synthesize instructions.
     */
    public function generateSkillContent(string $topic): string
    {
        $relevantMemories = Memory::query()
            ->where('key', 'like', "%{$topic}%")
            ->orWhere('value', 'like', "%{$topic}%")
            ->limit(50)
            ->get();

        $relevantMessages = Message::query()
            ->where('content', 'like', "%{$topic}%")
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['content', 'role']);

        $context = $this->buildContext($topic, $relevantMemories, $relevantMessages);

        try {
            $response = app(SkillContentAgent::class)->prompt($context);
            $content = trim($response->text);

            if ($content === '') {
                return $this->fallbackContent($topic, $relevantMemories);
            }

            // Enforce token limit (approximate: 4 chars per token)
            if (mb_strlen($content) > self::MAX_SKILL_TOKENS * 4) {
                $content = mb_substr($content, 0, self::MAX_SKILL_TOKENS * 4);
            }

            return $content;
        } catch (Throwable $e) {
            Log::debug('Skill content generation failed, using fallback', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackContent($topic, $relevantMemories);
        }
    }

    /**
     * Propose a new skill for a detected topic.
     *
     * Creates a draft skill with source='ai_generated' and is_active=false.
     * Does NOT auto-activate — requires user confirmation.
     */
    public function proposeSkill(string $topic): ?Skill
    {
        $slug = Str::slug($topic);

        // Don't create duplicates
        if (Skill::query()->where('slug', $slug)->exists()) {
            Log::debug('Skill already exists, skipping proposal', ['slug' => $slug]);

            return null;
        }

        $content = $this->generateSkillContent($topic);

        if ($content === '') {
            return null;
        }

        return Skill::query()->create([
            'name' => Str::title($topic),
            'slug' => $slug,
            'description' => "AI-generated skill based on your conversations about {$topic}.",
            'instructions' => $content,
            'category' => 'personal',
            'source' => 'ai_generated',
            'version' => '1.0.0',
            'is_active' => false,
        ]);
    }

    /**
     * Extract meaningful keywords from a memory key and value.
     *
     * @return list<string>
     */
    private function extractKeywords(string $key, string $value): array
    {
        $keywords = [];

        // Extract from dot-notation key (e.g. "user.preference.theme" → "theme")
        $keyParts = explode('.', $key);

        foreach ($keyParts as $part) {
            $part = mb_strtolower(trim($part));

            if ($part !== '' && ! in_array($part, $this->stopWords(), true) && mb_strlen($part) > 2) {
                $keywords[] = $part;
            }
        }

        // Extract significant words from value
        $words = preg_split('/[\s,.\-_]+/', mb_strtolower($value));

        if (is_array($words)) {
            foreach ($words as $word) {
                $word = trim($word);

                if ($word !== '' && ! in_array($word, $this->stopWords(), true) && mb_strlen($word) > 3) {
                    $keywords[] = $word;
                }
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * Build the context prompt for the SkillContentAgent.
     */
    private function buildContext(string $topic, Collection $memories, Collection $messages): string
    {
        $parts = ["Generate a skill about: {$topic}\n"];

        if ($memories->isNotEmpty()) {
            $parts[] = "## Relevant memories\n";

            foreach ($memories->take(20) as $memory) {
                $parts[] = "- [{$memory->type->value}] {$memory->key}: {$memory->value}";
            }

            $parts[] = '';
        }

        if ($messages->isNotEmpty()) {
            $parts[] = "## Relevant conversation excerpts\n";

            foreach ($messages->take(15) as $message) {
                $role = $message->role->value ?? 'unknown';
                $content = Str::limit($message->content, 200);
                $parts[] = "- [{$role}]: {$content}";
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Generate a basic fallback skill content without LLM.
     */
    private function fallbackContent(string $topic, Collection $memories): string
    {
        $lines = [
            "# {$topic}",
            '',
            "This skill covers knowledge about {$topic} based on your conversations.",
            '',
            '## Key Points',
            '',
        ];

        foreach ($memories->take(10) as $memory) {
            $lines[] = "- {$memory->value}";
        }

        return implode("\n", $lines);
    }

    /**
     * Common stop words to exclude from keyword extraction.
     *
     * @return list<string>
     */
    private function stopWords(): array
    {
        return [
            'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can',
            'has', 'her', 'was', 'one', 'our', 'out', 'with', 'that', 'this',
            'from', 'they', 'been', 'have', 'will', 'each', 'make', 'like',
            'user', 'true', 'false', 'null', 'value', 'data',
        ];
    }
}
