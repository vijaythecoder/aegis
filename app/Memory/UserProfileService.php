<?php

namespace App\Memory;

use App\Agent\ProfileSummaryAgent;
use App\Enums\MemoryType;
use App\Models\Memory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
        try {
            $response = app(ProfileSummaryAgent::class)->prompt("User memories:\n{$memories}");

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
