<?php

namespace App\Messaging\Adapters;

use App\Messaging\AdapterCapabilities;
use App\Messaging\BaseAdapter;
use App\Messaging\IncomingMessage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class IMessageAdapter extends BaseAdapter
{
    private Closure $processRunner;

    public function __construct(?Closure $processRunner = null)
    {
        $this->processRunner = $processRunner ?? function (string $command): array {
            $process = proc_open($command, [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            if (! is_resource($process)) {
                return ['exitCode' => 1, 'output' => '', 'error' => 'Failed to start process'];
            }

            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            return [
                'exitCode' => $exitCode,
                'output' => is_string($output) ? $output : '',
                'error' => is_string($error) ? $error : '',
            ];
        };
    }

    public function isAvailable(): bool
    {
        return PHP_OS === 'Darwin';
    }

    public function sendMessage(string $channelId, string $content): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        if (! $this->checkRateLimit()) {
            return;
        }

        foreach ($this->splitMessage($content, $this->getCapabilities()->maxMessageLength) as $chunk) {
            $script = $this->buildSendScript($channelId, $chunk);
            $command = sprintf('osascript -e %s', escapeshellarg($script));

            $this->safeExecute(function () use ($command): void {
                ($this->processRunner)($command);
            });
        }
    }

    public function sendMedia(string $channelId, string $path, string $type): void
    {
        $this->sendMessage($channelId, sprintf('[%s: %s]', strtoupper($type), basename($path)));
    }

    public function registerWebhook(string $url): void
    {
    }

    public function handleIncomingMessage(Request $request): IncomingMessage
    {
        $payload = (array) json_decode((string) $request->getContent(), true);

        $sender = (string) ($payload['sender'] ?? '');
        $content = (string) ($payload['content'] ?? '');
        $chatId = (string) ($payload['chat_id'] ?? $sender);
        $date = isset($payload['date']) ? Carbon::parse((string) $payload['date']) : null;

        return new IncomingMessage(
            platform: 'imessage',
            channelId: $chatId,
            senderId: $sender,
            content: $content,
            timestamp: $date,
            rawPayload: $payload,
        );
    }

    public function getName(): string
    {
        return 'imessage';
    }

    public function getCapabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            supportsMedia: false,
            supportsButtons: false,
            supportsMarkdown: false,
            maxMessageLength: 20000,
            supportsEditing: false,
        );
    }

    public function buildSendScript(string $recipient, string $message): string
    {
        $escapedMessage = str_replace(['\\', '"'], ['\\\\', '\\"'], $message);
        $escapedRecipient = str_replace(['\\', '"'], ['\\\\', '\\"'], $recipient);

        return <<<APPLESCRIPT
tell application "Messages"
    set targetService to 1st account whose service type = iMessage
    set targetBuddy to participant "{$escapedRecipient}" of targetService
    send "{$escapedMessage}" to targetBuddy
end tell
APPLESCRIPT;
    }

    public function buildPollScript(): string
    {
        return <<<'APPLESCRIPT'
tell application "Messages"
    set recentMessages to {}
    repeat with aChat in chats
        set chatMessages to messages of aChat
        if (count of chatMessages) > 0 then
            set lastMsg to last item of chatMessages
            set end of recentMessages to {sender:(handle of sender of lastMsg), content:(text of lastMsg), chat_id:(id of aChat)}
        end if
    end repeat
    return recentMessages
end tell
APPLESCRIPT;
    }

    public function parsePolledMessages(string $rawOutput): array
    {
        if ($rawOutput === '') {
            return [];
        }

        $decoded = json_decode($rawOutput, true);
        if (! is_array($decoded)) {
            return [];
        }

        $messages = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sender = (string) ($item['sender'] ?? '');
            $content = (string) ($item['content'] ?? '');
            $chatId = (string) ($item['chat_id'] ?? $sender);
            $date = isset($item['date']) ? Carbon::parse((string) $item['date']) : null;

            if ($sender === '' || $content === '') {
                continue;
            }

            $messages[] = new IncomingMessage(
                platform: 'imessage',
                channelId: $chatId,
                senderId: $sender,
                content: $content,
                timestamp: $date,
                rawPayload: $item,
            );
        }

        return $messages;
    }
}
