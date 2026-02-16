<?php

namespace App\Messaging;

use App\Agent\AegisAgent;
use App\Jobs\ExtractMemoriesJob;
use App\Messaging\Contracts\MessagingAdapter;
use App\Tools\BrowserTool;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Streaming\Events\TextDelta;

class MessageRouter
{
    private array $adapters = [];

    public function __construct(
        private readonly SessionBridge $sessionBridge,
        private readonly ?AegisAgent $agent = null,
    ) {}

    public function route(IncomingMessage $message): RoutedResponse
    {
        $conversation = $this->sessionBridge->resolveConversation(
            $message->platform,
            $message->channelId,
            $message->senderId,
        );

        $agent = $this->agent ?? app(AegisAgent::class);

        $agent->forConversation((string) $conversation->id);

        $tools = $agent->tools();
        $toolCount = is_countable($tools) ? count($tools) : iterator_count($tools);

        Log::debug('[MessageRouter] Routing message', [
            'platform' => $message->platform,
            'conversation_id' => $conversation->id,
            'tool_count' => $toolCount,
        ]);

        BrowserTool::flushScreenshots();

        $stream = $agent->stream($message->content);

        $responseText = '';

        foreach ($stream as $event) {
            if ($event instanceof TextDelta) {
                $responseText .= $event->delta;
            }
        }

        if (trim($responseText) !== '') {
            ExtractMemoriesJob::dispatch($message->content, $responseText, $conversation->id);
        }

        $attachments = array_map(
            fn (string $path): array => ['path' => $path, 'type' => 'photo'],
            BrowserTool::flushScreenshots(),
        );

        return new RoutedResponse($responseText, $attachments);
    }

    public function registerAdapter(string $platform, MessagingAdapter $adapter): void
    {
        $this->adapters[strtolower($platform)] = $adapter;
    }

    public function getAdapter(string $platform): ?MessagingAdapter
    {
        return $this->adapters[strtolower($platform)] ?? null;
    }
}
