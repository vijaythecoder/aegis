<?php

namespace App\Messaging\Contracts;

use App\Messaging\AdapterCapabilities;
use App\Messaging\IncomingMessage;
use Illuminate\Http\Request;

interface MessagingAdapter
{
    public function sendMessage(string $channelId, string $content): void;

    public function sendMedia(string $channelId, string $path, string $type): void;

    public function registerWebhook(string $url): void;

    public function handleIncomingMessage(Request $request): IncomingMessage;

    public function getName(): string;

    public function getCapabilities(): AdapterCapabilities;
}
