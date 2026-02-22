<?php

namespace App\Messaging;

use App\Agent\AegisAgent;
use App\Agent\AgentRegistry;
use App\Agent\DynamicAgent;
use App\Jobs\ExtractMemoriesJob;
use App\Messaging\Contracts\MessagingAdapter;
use App\Models\ChannelAgent;
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

        $agent = $this->resolveAgentForChannel($message->platform);

        $agent->forConversation((string) $conversation->id);

        $tools = $agent->tools();
        $toolCount = is_countable($tools) ? count($tools) : iterator_count($tools);

        Log::debug('[MessageRouter] Routing message', [
            'platform' => $message->platform,
            'conversation_id' => $conversation->id,
            'tool_count' => $toolCount,
            'agent_type' => $agent::class,
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

        $screenshots = BrowserTool::flushScreenshots();

        Log::debug('[MessageRouter] Attachments collected', ['screenshot_count' => count($screenshots), 'paths' => $screenshots]);

        $attachments = array_map(
            fn (string $path): array => ['path' => $path, 'type' => 'photo'],
            $screenshots,
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

    private function resolveAgentForChannel(string $platform): AegisAgent|DynamicAgent
    {
        if ($this->agent !== null) {
            return $this->agent;
        }

        $channelAgent = ChannelAgent::forChannel(strtolower($platform));

        if ($channelAgent !== null && $channelAgent->agent_id !== null) {
            try {
                return app(AgentRegistry::class)->resolve($channelAgent->agent_id);
            } catch (\Throwable) {
                Log::warning('[MessageRouter] Channel agent resolution failed, using default', [
                    'channel' => $platform,
                    'agent_id' => $channelAgent->agent_id,
                ]);
            }
        }

        return app(AegisAgent::class);
    }
}
