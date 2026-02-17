<?php

namespace App\Agent;

use App\Messaging\Adapters\TelegramAdapter;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessagingChannel;
use App\Models\ProactiveTask;
use App\Models\ProactiveTaskRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        $startedAt = now();

        $run = ProactiveTaskRun::query()->create([
            'proactive_task_id' => $task->id,
            'status' => 'success',
            'started_at' => $startedAt,
            'delivery_status' => 'pending',
        ]);

        try {
            $response = $this->agent->prompt($task->prompt);

            $tokensUsed = $response->usage->promptTokens + $response->usage->completionTokens;
            $summary = $this->buildSummary($task, $response->text, $tokensUsed);

            $run->update([
                'completed_at' => now(),
                'response_summary' => $summary,
                'tokens_used' => $tokensUsed,
                'estimated_cost' => $this->estimateCost($response),
            ]);

            $deliveryStatus = $this->deliver($task, $response->text);

            $run->update(['delivery_status' => $deliveryStatus]);

            return $response->text;
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => Str::limit($e->getMessage(), 500),
                'delivery_status' => 'failed',
            ]);

            $this->deliverFailureAlert($task, $e);

            throw $e;
        }
    }

    private function buildSummary(ProactiveTask $task, string $responseText, int $tokensUsed): string
    {
        $preview = Str::limit($responseText, 300);

        return "[{$task->name}] {$preview} (tokens: {$tokensUsed})";
    }

    private function estimateCost($response): float
    {
        try {
            $costEstimator = app(\App\Services\CostEstimator::class);

            $costData = $costEstimator->estimate(
                provider: $response->meta->provider ?? 'unknown',
                model: $response->meta->model ?? 'unknown',
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
                cacheReadTokens: $response->usage->cacheReadInputTokens,
                cacheWriteTokens: $response->usage->cacheWriteInputTokens,
                reasoningTokens: $response->usage->reasoningTokens,
            );

            return (float) ($costData['estimated_cost'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function deliver(ProactiveTask $task, string $content): string
    {
        try {
            match ($task->delivery_channel) {
                'telegram' => $this->deliverToTelegram($task, $content),
                default => $this->deliverToChat($task, $content),
            };

            return 'sent';
        } catch (Throwable $e) {
            Log::warning('aegis.proactive.delivery_failed', [
                'task' => $task->name,
                'channel' => $task->delivery_channel,
                'error' => $e->getMessage(),
            ]);

            if ($task->delivery_channel !== 'chat') {
                $this->deliverToChat($task, $content);
            }

            return 'fallback';
        }
    }

    private function deliverFailureAlert(ProactiveTask $task, Throwable $error): void
    {
        try {
            $alertContent = "⚠ Automation \"{$task->name}\" failed: {$error->getMessage()}";

            $this->deliverToChat($task, $alertContent);
        } catch (Throwable $e) {
            Log::error('aegis.proactive.failure_alert_failed', [
                'task' => $task->name,
                'error' => $e->getMessage(),
            ]);
        }
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

        app(TelegramAdapter::class)->sendMessage($channel->platform_channel_id, $content, null);
    }
}
