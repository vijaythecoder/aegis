<?php

namespace App\Memory;

use App\Models\Conversation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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

    public function applyAutoTitleFromMessage(Conversation $conversation, string $message): void
    {
        if (trim((string) $conversation->title) !== '') {
            return;
        }

        $title = $this->titleFromMessage($message);
        $conversation->forceFill(['title' => $title])->save();
    }

    public function titleFromMessage(string $message): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        if ($message === '') {
            return 'New conversation';
        }

        return mb_substr($message, 0, 50);
    }
}
