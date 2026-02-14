<?php

namespace App\Memory;

use App\Enums\MemoryType;
use App\Models\Conversation;

class FactExtractor
{
    public function __construct(private readonly MemoryService $memoryService) {}

    public function extract(string $assistantResponse, ?Conversation $conversation = null): array
    {
        $facts = [];
        $conversationId = $conversation?->id;

        if (preg_match('/\bmy name is\s+([a-z][a-z\s\'-]{1,40})/i', $assistantResponse, $nameMatch) === 1) {
            $name = trim(rtrim($nameMatch[1], '.!,;:'));
            $facts[] = ['type' => MemoryType::Fact, 'key' => 'user.name', 'value' => ucwords(strtolower($name))];
        }

        if (preg_match('/\bi prefer\s+([^\.\!\n]+)/i', $assistantResponse, $preferMatch) === 1) {
            $preference = trim($preferMatch[1]);
            if (preg_match('/\bdark mode\b/i', $preference) === 1) {
                $facts[] = ['type' => MemoryType::Preference, 'key' => 'user.preference.dark_mode', 'value' => 'dark mode'];
            } else {
                $facts[] = ['type' => MemoryType::Preference, 'key' => 'user.preference', 'value' => $preference];
            }
        }

        if (preg_match('/\bi use\s+([a-z0-9_\-]+)/i', $assistantResponse, $toolMatch) === 1) {
            $tool = strtolower(trim($toolMatch[1]));
            $facts[] = ['type' => MemoryType::Fact, 'key' => 'user.tool.'.$tool, 'value' => $tool];
        }

        $stored = [];

        foreach ($facts as $fact) {
            $this->memoryService->store(
                $fact['type'],
                $fact['key'],
                $fact['value'],
                $conversationId,
                0.9,
            );

            $stored[] = $fact;
        }

        return $stored;
    }
}
