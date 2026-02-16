<?php

namespace App\Jobs;

use App\Enums\MemoryType;
use App\Memory\EmbeddingService;
use App\Memory\MemoryService;
use App\Memory\UserProfileService;
use App\Memory\VectorStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Throwable;

class ExtractMemoriesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        private readonly string $userMessage,
        private readonly string $assistantResponse,
        private readonly ?int $conversationId = null,
    ) {}

    public function handle(
        MemoryService $memoryService,
        EmbeddingService $embeddingService,
        VectorStore $vectorStore,
        UserProfileService $userProfileService,
    ): void {
        if (! config('aegis.memory.fact_extraction', true)) {
            return;
        }

        $extracted = $this->extractViaLlm();

        if ($extracted === []) {
            return;
        }

        $shouldRefreshProfile = false;

        foreach ($extracted as $item) {
            $type = MemoryType::tryFrom($item['type'] ?? '');

            if ($type === null) {
                continue;
            }

            $key = trim($item['key'] ?? '');
            $value = trim($item['value'] ?? '');

            if ($key === '' || $value === '') {
                continue;
            }

            $memory = $memoryService->store($type, $key, $value, $this->conversationId, 0.9);

            $embedding = $embeddingService->embed("{$key}: {$value}");

            if ($embedding !== null) {
                $vectorStore->store($embedding, [
                    'source_type' => 'memory',
                    'source_id' => $memory->id,
                    'content_preview' => "{$key}: {$value}",
                ]);
            }

            $shouldRefreshProfile = true;
        }

        if ($shouldRefreshProfile) {
            $userProfileService->invalidate();
        }
    }

    /**
     * @return array<int, array{type: string, key: string, value: string}>
     */
    private function extractViaLlm(): array
    {
        $provider = (string) config('aegis.agent.summary_provider', 'anthropic');
        $model = (string) config('aegis.agent.summary_model', 'claude-3-5-haiku-latest');

        $combinedText = mb_substr("User: {$this->userMessage}\nAssistant: {$this->assistantResponse}", 0, 3000);

        try {
            $response = Prism::text()
                ->using($provider, $model)
                ->withClientOptions(['timeout' => 15])
                ->withSystemPrompt(implode("\n", [
                    'Extract memorable facts, preferences, and notes from this conversation exchange.',
                    'Return a JSON array of objects with keys: "type", "key", "value".',
                    'Types: "fact" (personal info like name, job, location, timezone), "preference" (likes, dislikes, tool preferences), "note" (project details, important context).',
                    'Keys should be dot-notation identifiers like: user.name, user.timezone, user.preference.theme, project.aegis.stack.',
                    'Only extract EXPLICIT information stated by the user. Do NOT infer or guess.',
                    'If nothing worth remembering, return an empty array: []',
                    'Return ONLY valid JSON, no explanation.',
                ]))
                ->withPrompt($combinedText)
                ->asText();

            $text = trim($response->text);

            if ($text === '' || $text === '[]') {
                return [];
            }

            $text = $this->extractJsonFromText($text);
            $decoded = json_decode($text, true);

            if (! is_array($decoded)) {
                return [];
            }

            return array_filter($decoded, fn ($item): bool => is_array($item)
                && isset($item['type'], $item['key'], $item['value'])
                && is_string($item['type'])
                && is_string($item['key'])
                && is_string($item['value']));
        } catch (Throwable $e) {
            Log::debug('LLM memory extraction failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function extractJsonFromText(string $text): string
    {
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return $text;
    }
}
