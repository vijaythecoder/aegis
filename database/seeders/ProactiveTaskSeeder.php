<?php

namespace Database\Seeders;

use App\Models\ProactiveTask;
use Illuminate\Database\Seeder;

class ProactiveTaskSeeder extends Seeder
{
    public function run(): void
    {
        $tasks = [
            [
                'name' => 'Morning Briefing',
                'schedule' => '0 8 * * 1-5',
                'prompt' => 'Give me a morning briefing. Summarize any pending tasks, recent conversations, and anything important I should know to start my day.',
                'delivery_channel' => 'chat',
                'is_active' => false,
            ],
            [
                'name' => 'Memory Digest',
                'schedule' => '0 18 * * 0',
                'prompt' => 'Create a weekly digest of new things you have learned about me this week. Summarize new facts, preferences, and notes stored in memory.',
                'delivery_channel' => 'chat',
                'is_active' => false,
            ],
            [
                'name' => 'Stale Conversation Nudge',
                'schedule' => '0 10 * * *',
                'prompt' => 'Check for any conversations that have been inactive for more than 3 days but seem unfinished. Remind me about them with a brief summary of where we left off.',
                'delivery_channel' => 'chat',
                'is_active' => false,
            ],
            [
                'name' => 'API Key Expiration',
                'schedule' => '0 9 * * 1',
                'prompt' => 'Check the configured API keys and warn me if any appear to be approaching expiration or have recently stopped working.',
                'delivery_channel' => 'chat',
                'is_active' => false,
            ],
        ];

        foreach ($tasks as $task) {
            ProactiveTask::query()->firstOrCreate(
                ['name' => $task['name']],
                $task,
            );
        }
    }
}
