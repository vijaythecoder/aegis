<?php

namespace App\Agent;

use App\Messaging\Adapters\TelegramAdapter;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessagingChannel;
use App\Models\ProactiveTask;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProactiveTaskRunner
{
    public function __construct(
        private readonly AegisAgent $agent,
    ) {}

    public function runDueTasks(): int
    {
        $tasks = ProactiveTask::query()->due()->get();

        $ran = 0;

        foreach ($tasks as $task) {
            try {
                $this->runTask($task);
                $ran++;
            } catch (Throwable $e) {
                Log::warning('aegis.proactive.task_failed', [
                    'task' => $task->name,
                    'error' => $e->getMessage(),
                ]);
            }

            $task->updateNextRun();
        }

        return $ran;
    }

    public function runTask(ProactiveTask $task): string
    {
        $response = $this->agent->prompt($task->prompt);

        $this->deliver($task, $response->text);

        return $response->text;
    }

    private function deliver(ProactiveTask $task, string $content): void
    {
        match ($task->delivery_channel) {
            'telegram' => $this->deliverToTelegram($task, $content),
            default => $this->deliverToChat($task, $content),
        };
    }

    private function deliverToChat(ProactiveTask $task, string $content): void
    {
        $conversation = Conversation::create([
            'title' => $task->name,
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $content,
        ]);
    }

    private function deliverToTelegram(ProactiveTask $task, string $content): void
    {
        $channel = MessagingChannel::query()
            ->where('platform', 'telegram')
            ->where('active', true)
            ->latest('updated_at')
            ->first();

        if (! $channel instanceof MessagingChannel) {
            Log::warning('aegis.proactive.no_telegram_channel', [
                'task' => $task->name,
            ]);
            $this->deliverToChat($task, $content);

            return;
        }

        try {
            app(TelegramAdapter::class)->sendMessage($channel->platform_channel_id, $content);
        } catch (Throwable $e) {
            Log::warning('aegis.proactive.telegram_failed', [
                'task' => $task->name,
                'error' => $e->getMessage(),
            ]);
            $this->deliverToChat($task, $content);
        }
    }
}
