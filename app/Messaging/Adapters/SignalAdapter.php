<?php

namespace App\Messaging\Adapters;

use App\Messaging\AdapterCapabilities;
use App\Messaging\BaseAdapter;
use App\Messaging\IncomingMessage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SignalAdapter extends BaseAdapter
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

    public function isSignalCliInstalled(): bool
    {
        $cliPath = $this->getCliPath();
        $result = ($this->processRunner)(sprintf('%s --version 2>&1', escapeshellarg($cliPath)));

        return ($result['exitCode'] ?? 1) === 0;
    }

    public function sendMessage(string $channelId, string $content): void
    {
        if (! $this->checkRateLimit()) {
            return;
        }

        foreach ($this->splitMessage($content, $this->getCapabilities()->maxMessageLength) as $chunk) {
            $command = $this->buildSendCommand($channelId, $chunk);

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

        $envelope = (array) ($payload['envelope'] ?? []);
        $dataMessage = (array) ($envelope['dataMessage'] ?? []);

        $source = (string) ($envelope['source'] ?? '');
        $content = (string) ($dataMessage['message'] ?? '');
        $timestamp = isset($envelope['timestamp']) && is_numeric($envelope['timestamp'])
            ? Carbon::createFromTimestampMsUTC((int) $envelope['timestamp'])
            : null;

        return new IncomingMessage(
            platform: 'signal',
            channelId: $source,
            senderId: $source,
            content: $content,
            timestamp: $timestamp,
            rawPayload: $payload,
        );
    }

    public function getName(): string
    {
        return 'signal';
    }

    public function getCapabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            supportsMedia: false,
            supportsButtons: false,
            supportsMarkdown: false,
            maxMessageLength: 4096,
            supportsEditing: false,
        );
    }

    public function buildReceiveCommand(): string
    {
        $cliPath = $this->getCliPath();
        $account = $this->getAccountNumber();

        $parts = [escapeshellarg($cliPath)];

        if ($account !== '') {
            $parts[] = '-a';
            $parts[] = escapeshellarg($account);
        }

        $parts[] = 'receive';
        $parts[] = '--json';
        $parts[] = '--timeout';
        $parts[] = '5';

        return implode(' ', $parts);
    }

    public function buildSendCommand(string $recipient, string $message): string
    {
        $cliPath = $this->getCliPath();
        $account = $this->getAccountNumber();

        $parts = [escapeshellarg($cliPath)];

        if ($account !== '') {
            $parts[] = '-a';
            $parts[] = escapeshellarg($account);
        }

        $parts[] = 'send';
        $parts[] = '-m';
        $parts[] = escapeshellarg($message);
        $parts[] = escapeshellarg($recipient);

        return implode(' ', $parts);
    }

    public function parseReceivedMessages(string $rawOutput): array
    {
        if (trim($rawOutput) === '') {
            return [];
        }

        $messages = [];
        $lines = explode("\n", trim($rawOutput));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }

            $envelope = (array) ($decoded['envelope'] ?? []);
            $dataMessage = $envelope['dataMessage'] ?? null;

            if (! is_array($dataMessage) || ! isset($dataMessage['message'])) {
                continue;
            }

            $source = (string) ($envelope['source'] ?? '');
            $content = (string) ($dataMessage['message'] ?? '');

            if ($source === '' || $content === '') {
                continue;
            }

            $timestamp = isset($envelope['timestamp']) && is_numeric($envelope['timestamp'])
                ? Carbon::createFromTimestampMsUTC((int) $envelope['timestamp'])
                : null;

            $messages[] = new IncomingMessage(
                platform: 'signal',
                channelId: $source,
                senderId: $source,
                content: $content,
                timestamp: $timestamp,
                rawPayload: $decoded,
            );
        }

        return $messages;
    }

    private function getCliPath(): string
    {
        return (string) config('aegis.messaging.signal.signal_cli_path', 'signal-cli');
    }

    private function getAccountNumber(): string
    {
        return (string) config('aegis.messaging.signal.phone_number', '');
    }
}
