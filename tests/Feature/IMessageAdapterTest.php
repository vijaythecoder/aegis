<?php

use App\Messaging\AdapterCapabilities;
use App\Messaging\Adapters\IMessageAdapter;
use App\Messaging\IncomingMessage;

beforeEach(function () {
    $this->adapter = new IMessageAdapter;
});

it('returns imessage as adapter name', function () {
    expect($this->adapter->getName())->toBe('imessage');
});

it('detects macOS platform correctly', function () {
    $isMac = PHP_OS === 'Darwin';
    expect($this->adapter->isAvailable())->toBe($isMac);
});

it('returns correct capabilities for imessage', function () {
    $caps = $this->adapter->getCapabilities();

    expect($caps)->toBeInstanceOf(AdapterCapabilities::class)
        ->and($caps->supportsMedia)->toBeFalse()
        ->and($caps->supportsButtons)->toBeFalse()
        ->and($caps->supportsMarkdown)->toBeFalse()
        ->and($caps->maxMessageLength)->toBe(20000)
        ->and($caps->supportsEditing)->toBeFalse();
});

it('gracefully disables on non-macOS platforms', function () {
    if (PHP_OS !== 'Darwin') {
        expect($this->adapter->isAvailable())->toBeFalse();
    } else {
        expect($this->adapter->isAvailable())->toBeTrue();
    }
});

it('builds correct AppleScript send command', function () {
    $script = $this->adapter->buildSendScript('+15551234567', 'Hello from Aegis');

    expect($script)->toContain('tell application "Messages"')
        ->and($script)->toContain('Hello from Aegis')
        ->and($script)->toContain('+15551234567');
});

it('sends message via AppleScript on macOS', function () {
    $executed = false;
    $adapter = new IMessageAdapter(processRunner: function (string $command) use (&$executed) {
        $executed = true;
        expect($command)->toContain('osascript');

        return ['exitCode' => 0, 'output' => '', 'error' => ''];
    });

    if (PHP_OS !== 'Darwin') {
        $adapter->sendMessage('+15551234567', 'Test message');
        expect($executed)->toBeFalse();
    } else {
        $adapter->sendMessage('+15551234567', 'Test message');
        expect($executed)->toBeTrue();
    }
});

it('splits long messages before sending', function () {
    $sentChunks = [];
    $adapter = new IMessageAdapter(processRunner: function (string $command) use (&$sentChunks) {
        $sentChunks[] = $command;

        return ['exitCode' => 0, 'output' => '', 'error' => ''];
    });

    $longMessage = str_repeat('A', 25000);

    if (PHP_OS === 'Darwin') {
        $adapter->sendMessage('+15551234567', $longMessage);
        expect(count($sentChunks))->toBeGreaterThan(1);
    } else {
        $adapter->sendMessage('+15551234567', $longMessage);
        expect($sentChunks)->toBeEmpty();
    }
});

it('builds correct AppleScript poll command for new messages', function () {
    $script = $this->adapter->buildPollScript();

    expect($script)->toContain('tell application "Messages"')
        ->and($script)->toContain('messages');
});

it('parses polled messages into IncomingMessage objects', function () {
    $rawOutput = json_encode([
        [
            'sender' => '+15559876543',
            'content' => 'Hello Aegis!',
            'date' => '2026-02-13T10:30:00Z',
            'chat_id' => 'iMessage;-;+15559876543',
        ],
    ]);

    $messages = $this->adapter->parsePolledMessages($rawOutput);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(IncomingMessage::class)
        ->and($messages[0]->platform)->toBe('imessage')
        ->and($messages[0]->senderId)->toBe('+15559876543')
        ->and($messages[0]->content)->toBe('Hello Aegis!')
        ->and($messages[0]->channelId)->toBe('iMessage;-;+15559876543');
});

it('returns empty array when poll output is invalid', function () {
    $messages = $this->adapter->parsePolledMessages('not json');
    expect($messages)->toBeEmpty();

    $messages = $this->adapter->parsePolledMessages('');
    expect($messages)->toBeEmpty();
});

it('handles incoming message from request with poll data', function () {
    $request = \Illuminate\Http\Request::create('/webhook/imessage', 'POST', [], [], [], [], json_encode([
        'sender' => '+15559876543',
        'content' => 'Test from iMessage',
        'date' => '2026-02-13T12:00:00Z',
        'chat_id' => 'iMessage;-;+15559876543',
    ]));
    $request->headers->set('Content-Type', 'application/json');

    $message = $this->adapter->handleIncomingMessage($request);

    expect($message)->toBeInstanceOf(IncomingMessage::class)
        ->and($message->platform)->toBe('imessage')
        ->and($message->content)->toBe('Test from iMessage')
        ->and($message->senderId)->toBe('+15559876543');
});

it('escapes special characters in AppleScript strings', function () {
    $script = $this->adapter->buildSendScript('+15551234567', 'He said "hello" & it\'s fine');

    expect($script)->toContain('He said \\"hello\\"');
});

it('confirms adapter class exists regardless of platform', function () {
    if (PHP_OS === 'Darwin') {
        expect($this->adapter->isAvailable())->toBeTrue();
    }
    expect(class_exists(IMessageAdapter::class))->toBeTrue();
});

it('accepts custom chat db path', function () {
    $adapter = new IMessageAdapter(chatDbPath: '/tmp/test-chat.db');

    expect($adapter->getChatDbPath())->toBe('/tmp/test-chat.db');
});

it('reports chat db as inaccessible when file does not exist', function () {
    $adapter = new IMessageAdapter(chatDbPath: '/nonexistent/chat.db');

    expect($adapter->isChatDbAccessible())->toBeFalse();
});

it('returns zero max row id when db reader returns empty', function () {
    $adapter = new IMessageAdapter(
        dbReader: fn (string $sql, array $params) => [],
    );

    expect($adapter->getMaxRowId())->toBe(0);
});

it('returns max row id from db reader', function () {
    $adapter = new IMessageAdapter(
        dbReader: fn (string $sql, array $params) => [['max_id' => 42]],
    );

    expect($adapter->getMaxRowId())->toBe(42);
});

it('polls chat database and returns incoming messages', function () {
    $fakeRows = [
        [
            'ROWID' => 100,
            'text' => 'Hello from iMessage!',
            'date' => 700000000000000000,
            'is_from_me' => 0,
            'handle_id' => '+15559876543',
            'chat_identifier' => 'iMessage;-;+15559876543',
        ],
        [
            'ROWID' => 101,
            'text' => 'Second message',
            'date' => 700000001000000000,
            'is_from_me' => 0,
            'handle_id' => '+15551112222',
            'chat_identifier' => 'iMessage;-;+15551112222',
        ],
    ];

    $adapter = new IMessageAdapter(
        dbReader: fn (string $sql, array $params) => $fakeRows,
    );

    $result = $adapter->pollChatDatabase(99);

    expect($result)->toBeArray()
        ->and($result['messages'])->toHaveCount(2)
        ->and($result['maxRowId'])->toBe(101)
        ->and($result['messages'][0])->toBeInstanceOf(IncomingMessage::class)
        ->and($result['messages'][0]->platform)->toBe('imessage')
        ->and($result['messages'][0]->content)->toBe('Hello from iMessage!')
        ->and($result['messages'][0]->senderId)->toBe('+15559876543')
        ->and($result['messages'][0]->channelId)->toBe('iMessage;-;+15559876543')
        ->and($result['messages'][1]->content)->toBe('Second message');
});

it('skips messages with empty text or handle', function () {
    $fakeRows = [
        [
            'ROWID' => 200,
            'text' => '',
            'date' => null,
            'is_from_me' => 0,
            'handle_id' => '+15559876543',
            'chat_identifier' => 'iMessage;-;+15559876543',
        ],
        [
            'ROWID' => 201,
            'text' => 'Valid message',
            'date' => null,
            'is_from_me' => 0,
            'handle_id' => '',
            'chat_identifier' => '',
        ],
    ];

    $adapter = new IMessageAdapter(
        dbReader: fn (string $sql, array $params) => $fakeRows,
    );

    $result = $adapter->pollChatDatabase(199);

    expect($result['messages'])->toBeEmpty()
        ->and($result['maxRowId'])->toBe(201);
});

it('returns empty messages when db reader returns empty', function () {
    $adapter = new IMessageAdapter(
        dbReader: fn (string $sql, array $params) => [],
    );

    $result = $adapter->pollChatDatabase(0);

    expect($result['messages'])->toBeEmpty()
        ->and($result['maxRowId'])->toBe(0);
});

it('converts apple core data timestamps correctly', function () {
    $appleDate = 700000000000000000;
    $expectedUnix = (int) ($appleDate / 1_000_000_000) + 978307200;

    $adapter = new IMessageAdapter(
        dbReader: fn (string $sql, array $params) => [[
            'ROWID' => 1,
            'text' => 'Timestamped',
            'date' => $appleDate,
            'is_from_me' => 0,
            'handle_id' => '+15551234567',
            'chat_identifier' => 'iMessage;-;+15551234567',
        ]],
    );

    $result = $adapter->pollChatDatabase(0);
    $msg = $result['messages'][0];

    expect($msg->timestamp)->not->toBeNull()
        ->and($msg->timestamp->timestamp)->toBe($expectedUnix);
});

it('handles null timestamps gracefully', function () {
    $adapter = new IMessageAdapter(
        dbReader: fn (string $sql, array $params) => [[
            'ROWID' => 1,
            'text' => 'No timestamp',
            'date' => null,
            'is_from_me' => 0,
            'handle_id' => '+15551234567',
            'chat_identifier' => 'iMessage;-;+15551234567',
        ]],
    );

    $result = $adapter->pollChatDatabase(0);

    expect($result['messages'][0]->timestamp)->toBeNull();
});

it('passes contact filter to sql query via params', function () {
    $capturedParams = [];

    $adapter = new IMessageAdapter(
        dbReader: function (string $sql, array $params) use (&$capturedParams) {
            $capturedParams = $params;

            return [];
        },
    );

    $adapter->pollChatDatabase(50, ['+15551234567', 'friend@icloud.com']);

    expect($capturedParams)->toBe([50, '+15551234567', 'friend@icloud.com']);
});

it('builds IN clause for multiple contacts', function () {
    $capturedSql = '';

    $adapter = new IMessageAdapter(
        dbReader: function (string $sql, array $params) use (&$capturedSql) {
            $capturedSql = $sql;

            return [];
        },
    );

    $adapter->pollChatDatabase(0, ['+15551111111', '+15552222222']);

    expect($capturedSql)->toContain('AND h.id IN (?, ?)');
});

it('omits contact clause when filter is empty', function () {
    $capturedSql = '';

    $adapter = new IMessageAdapter(
        dbReader: function (string $sql, array $params) use (&$capturedSql) {
            $capturedSql = $sql;

            return [];
        },
    );

    $adapter->pollChatDatabase(0, []);

    expect($capturedSql)->not->toContain('AND h.id IN');
});

it('builds chat-id based AppleScript when channelId contains semicolons', function () {
    $adapter = new IMessageAdapter;
    $script = $adapter->buildSendScript('iMessage;-;+15551234567', 'Hello');

    expect($script)->toContain('chat id "iMessage;-;+15551234567"')
        ->and($script)->toContain('send "Hello" to targetChat')
        ->and($script)->not->toContain('targetBuddy');
});

it('builds buddy based AppleScript when channelId is a plain number', function () {
    $adapter = new IMessageAdapter;
    $script = $adapter->buildSendScript('+15551234567', 'Hello');

    expect($script)->toContain('participant "+15551234567"')
        ->and($script)->toContain('send "Hello" to targetBuddy')
        ->and($script)->not->toContain('targetChat');
});
