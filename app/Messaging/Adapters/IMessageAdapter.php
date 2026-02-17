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
    /**
     * Apple's Core Data epoch offset (seconds between Unix epoch and 2001-01-01).
     */
    private const APPLE_EPOCH_OFFSET = 978307200;

    /**
     * Core Data timestamps are stored in nanoseconds.
     */
    private const NANOSECOND_DIVISOR = 1_000_000_000;

    private Closure $processRunner;

    private string $chatDbPath;

    private ?Closure $dbReader;

    public function __construct(
        ?Closure $processRunner = null,
        ?string $chatDbPath = null,
        ?Closure $dbReader = null,
    ) {
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

        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? '/Users/unknown';
        $this->chatDbPath = $chatDbPath ?? ($home.'/Library/Messages/chat.db');
        $this->dbReader = $dbReader;
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

    public function registerWebhook(string $url): void {}

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

        if (str_contains($recipient, ';')) {
            return <<<APPLESCRIPT
tell application "Messages"
    set targetChat to a reference to chat id "{$escapedRecipient}"
    send "{$escapedMessage}" to targetChat
end tell
APPLESCRIPT;
        }

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

    /**
     * Check if the chat.db file exists and is readable.
     */
    public function isChatDbAccessible(): bool
    {
        return file_exists($this->chatDbPath) && is_readable($this->chatDbPath);
    }

    /**
     * Get the path to the chat.db file.
     */
    public function getChatDbPath(): string
    {
        return $this->chatDbPath;
    }

    /**
     * Get the current maximum ROWID from the message table.
     * Used to set the initial offset so only new messages are processed.
     */
    public function getMaxRowId(): int
    {
        $rows = $this->queryDb('SELECT MAX(ROWID) as max_id FROM message');

        if ($rows === [] || ! isset($rows[0]['max_id'])) {
            return 0;
        }

        return (int) $rows[0]['max_id'];
    }

    /**
     * Poll chat.db for messages with ROWID greater than $sinceRowId.
     * When $filterContacts is provided, only messages from those handles (phone/email) are returned.
     *
     * @param  list<string>  $filterContacts
     * @return array{messages: list<IncomingMessage>, maxRowId: int}
     */
    public function pollChatDatabase(int $sinceRowId = 0, array $filterContacts = []): array
    {
        $params = [$sinceRowId];
        $contactClause = '';

        $filterContacts = array_values(array_filter(
            array_map('trim', $filterContacts),
            fn (string $c): bool => $c !== '',
        ));

        if ($filterContacts !== []) {
            $placeholders = implode(', ', array_fill(0, count($filterContacts), '?'));
            $contactClause = "AND h.id IN ({$placeholders})";
            $params = array_merge($params, $filterContacts);
        }

        $sql = <<<SQL
SELECT
    m.ROWID,
    m.text,
    m.date,
    m.is_from_me,
    h.id as handle_id,
    c.chat_identifier
FROM message m
LEFT JOIN handle h ON m.handle_id = h.ROWID
LEFT JOIN chat_message_join cmj ON m.ROWID = cmj.message_id
LEFT JOIN chat c ON cmj.chat_id = c.ROWID
WHERE m.ROWID > ?
    AND m.is_from_me = 0
    AND m.text IS NOT NULL
    AND m.text != ''
    {$contactClause}
ORDER BY m.ROWID ASC
LIMIT 50
SQL;

        $rows = $this->queryDb($sql, $params);

        $messages = [];
        $maxRowId = $sinceRowId;

        foreach ($rows as $row) {
            $rowId = (int) ($row['ROWID'] ?? 0);
            $text = (string) ($row['text'] ?? '');
            $handleId = (string) ($row['handle_id'] ?? '');
            $chatIdentifier = (string) ($row['chat_identifier'] ?? $handleId);

            if ($text === '' || $handleId === '') {
                $maxRowId = max($maxRowId, $rowId);

                continue;
            }

            $timestamp = $this->convertAppleTimestamp($row['date'] ?? null);

            $messages[] = new IncomingMessage(
                platform: 'imessage',
                channelId: $chatIdentifier,
                senderId: $handleId,
                content: $text,
                timestamp: $timestamp,
                rawPayload: $row,
            );

            $maxRowId = max($maxRowId, $rowId);
        }

        return [
            'messages' => $messages,
            'maxRowId' => $maxRowId,
        ];
    }

    /**
     * Convert Apple's Core Data timestamp to Carbon.
     * Core Data uses nanoseconds since 2001-01-01 00:00:00 UTC.
     */
    private function convertAppleTimestamp(mixed $appleDate): ?Carbon
    {
        if ($appleDate === null || $appleDate === '' || $appleDate === 0) {
            return null;
        }

        $unixTimestamp = ((int) $appleDate / self::NANOSECOND_DIVISOR) + self::APPLE_EPOCH_OFFSET;

        return Carbon::createFromTimestamp((int) $unixTimestamp);
    }

    /**
     * Execute a query against the chat.db SQLite database.
     *
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function queryDb(string $sql, array $params = []): array
    {
        if ($this->dbReader instanceof Closure) {
            return ($this->dbReader)($sql, $params);
        }

        if (! $this->isChatDbAccessible()) {
            return [];
        }

        try {
            $pdo = new \PDO('sqlite:'.$this->chatDbPath, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);

            $pdo->exec('PRAGMA query_only = ON');

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            /** @var list<array<string, mixed>> $results */
            $results = $stmt->fetchAll();

            return $results;
        } catch (\PDOException) {
            return [];
        }
    }
}
