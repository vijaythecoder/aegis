<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisConversationsList extends Command
{
    protected $signature = 'aegis:conversations:list';

    protected $description = 'List conversations with message counts and last activity';

    public function handle(): int
    {
        $conversations = Conversation::query()
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        if ($conversations->isEmpty()) {
            $this->warn('No conversations found.');

            return CommandStatus::SUCCESS;
        }

        $rows = $conversations->map(fn (Conversation $conversation): array => [
            $conversation->id,
            $conversation->title,
            $conversation->messages_count,
            $conversation->last_message_at?->toDateTimeString() ?? '-',
            $conversation->is_archived ? 'yes' : 'no',
        ])->all();

        $this->table(['ID', 'Title', 'Messages', 'Last Activity', 'Archived'], $rows);

        return CommandStatus::SUCCESS;
    }
}
