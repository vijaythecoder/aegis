<?php

namespace App\Memory;

use App\Enums\MemoryType;
use App\Models\Memory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Throwable;

class UserProfileService
{
    private const CACHE_KEY = 'aegis.user_profile';

    private const CACHE_TTL_MINUTES = 30;

    public function getProfile(): ?string
    {
        return Cache::get(self::CACHE_KEY);
    }

    public function refreshProfile(): ?string
    {
        $memories = $this->gatherMemories();

        if ($memories === '') {
            Cache::forget(self::CACHE_KEY);

            return null;
        }

        $profile = $this->generateProfileViaLlm($memories);

        if ($profile !== null) {
            Cache::put(self::CACHE_KEY, $profile, now()->addMinutes(self::CACHE_TTL_MINUTES));
        }

        return $profile;
    }

    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function gatherMemories(): string
    {
        $facts = Memory::query()
            ->where('type', MemoryType::Fact)
            ->orderByDesc('confidence')
            ->limit(30)
            ->get()
            ->map(fn (Memory $m): string => "- [fact] {$m->key}: {$m->value}")
            ->implode("\n");

        $preferences = Memory::query()
            ->where('type', MemoryType::Preference)
            ->orderByDesc('confidence')
            ->limit(20)
            ->get()
            ->map(fn (Memory $m): string => "- [preference] {$m->key}: {$m->value}")
            ->implode("\n");

        $notes = Memory::query()
            ->where('type', MemoryType::Note)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (Memory $m): string => "- [note] {$m->key}: {$m->value}")
            ->implode("\n");

        return collect([$facts, $preferences, $notes])
            ->filter(fn (string $s): bool => $s !== '')
            ->implode("\n");
    }

    private function generateProfileViaLlm(string $memories): ?string
    {
        $provider = (string) config('aegis.agent.summary_provider', 'anthropic');
        $model = (string) config('aegis.agent.summary_model', 'claude-3-5-haiku-latest');

        try {
            $response = Prism::text()
                ->using($provider, $model)
                ->withClientOptions(['timeout' => 15])
                ->withSystemPrompt(implode("\n", [
                    'You are a profile summarizer. Given a list of facts, preferences, and notes about a user, produce a concise user profile summary (150-300 tokens max).',
                    'Format: short sentences or phrases. Include name, timezone, tech stack, current projects, communication style, and key preferences.',
                    'Do NOT invent information. Only use what is provided. If a field has no data, skip it.',
                    'Return ONLY the summary text, no headers or labels.',
                ]))
                ->withPrompt("User memories:\n{$memories}")
                ->asText();

            $profile = trim($response->text);

            if ($profile === '' || mb_strlen($profile) > 2000) {
                return $this->fallbackProfile($memories);
            }

            return $profile;
        } catch (Throwable $e) {
            Log::debug('User profile generation failed, using fallback', ['error' => $e->getMessage()]);

            return $this->fallbackProfile($memories);
        }
    }

    private function fallbackProfile(string $memories): ?string
    {
        $lines = array_filter(explode("\n", $memories));

        if ($lines === []) {
            return null;
        }

        return implode("\n", array_slice($lines, 0, 10));
    }
}
