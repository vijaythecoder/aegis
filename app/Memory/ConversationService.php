<?php

namespace App\Memory;

use App\Models\Conversation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Throwable;

class ConversationService
{
    public function create(string $title, ?string $model = null, ?string $provider = null): Conversation
    {
        return Conversation::query()->create([
            'title' => trim($title),
            'model' => $model,
            'provider' => $provider,
        ]);
    }

    public function find(int $id): ?Conversation
    {
        return Conversation::query()->find($id);
    }

    public function list(int $perPage = 20): LengthAwarePaginator
    {
        return Conversation::query()
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function archive(int $id): void
    {
        Conversation::query()->whereKey($id)->update(['is_archived' => true]);
    }

    public function delete(int $id): void
    {
        Conversation::query()->whereKey($id)->delete();
    }

    public function updateTitle(int $id, string $title): void
    {
        Conversation::query()->whereKey($id)->update(['title' => trim($title)]);
    }

    public function generateTitle(int $conversationId, string $userMessage): void
    {
        $conversation = Conversation::query()->find($conversationId);

        if (! $conversation || trim((string) $conversation->title) !== '') {
            return;
        }

        $title = $this->generateTitleViaLlm($userMessage);
        $conversation->forceFill(['title' => $title])->save();
    }

    private function generateTitleViaLlm(string $userMessage): string
    {
        $fallback = mb_substr(trim((string) preg_replace('/\s+/', ' ', $userMessage)), 0, 50);

        if ($fallback === '') {
            return 'New conversation';
        }

        $provider = (string) config('aegis.agent.summary_provider', config('aegis.agent.default_provider', 'anthropic'));
        $model = (string) config('aegis.agent.summary_model', '');

        try {
            $response = Prism::text()
                ->using($provider, $model)
                ->withClientOptions(['timeout' => 10])
                ->withSystemPrompt('Generate a short conversation title (max 6 words) for the user message below. Return ONLY the title text, no quotes, no punctuation at the end.')
                ->withPrompt(mb_substr($userMessage, 0, 500))
                ->asText();

            $title = trim($response->text);

            if ($title === '' || mb_strlen($title) > 80) {
                return $fallback;
            }

            return $title;
        } catch (Throwable $e) {
            Log::debug('Title generation failed, using fallback', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }
}
