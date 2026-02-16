<?php

namespace App\Jobs;

use App\Memory\ConversationSummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SummarizeConversationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        private readonly int $conversationId,
    ) {}

    public function handle(ConversationSummaryService $summaryService): void
    {
        $summaryService->summarize($this->conversationId);
    }
}
