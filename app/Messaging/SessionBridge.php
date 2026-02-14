<?php

namespace App\Messaging;

use App\Models\Conversation;
use App\Models\MessagingChannel;

class SessionBridge
{
    public function resolveConversation(string $platform, string $channelId, string $senderId): Conversation
    {
        $channel = MessagingChannel::query()
            ->where('platform', $platform)
            ->where('platform_channel_id', $channelId)
            ->where('active', true)
            ->with('conversation')
            ->first();

        if ($channel?->conversation instanceof Conversation) {
            return $channel->conversation;
        }

        $conversation = Conversation::query()->create([
            'title' => sprintf('%s:%s', $platform, $channelId),
            'provider' => config('aegis.agent.default_provider'),
            'model' => config('aegis.agent.default_model'),
            'last_message_at' => now(),
        ]);

        MessagingChannel::query()->updateOrCreate(
            [
                'platform' => $platform,
                'platform_channel_id' => $channelId,
            ],
            [
                'platform_user_id' => $senderId,
                'conversation_id' => $conversation->id,
                'active' => true,
            ]
        );

        return $conversation;
    }

    public function getChannel(string $platform, string $channelId): ?MessagingChannel
    {
        return MessagingChannel::query()
            ->where('platform', $platform)
            ->where('platform_channel_id', $channelId)
            ->first();
    }
}
