<?php

use App\Messaging\Adapters\IMessageAdapter;
use App\Messaging\AdapterCapabilities;
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
