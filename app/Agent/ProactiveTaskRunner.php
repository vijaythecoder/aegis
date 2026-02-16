<?php

namespace App\Agent;

use App\Models\Conversation;
use App\Models\Message;
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
            'chat' => $this->deliverToChat($task, $content),
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
}
