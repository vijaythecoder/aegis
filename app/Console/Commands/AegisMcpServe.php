<?php

namespace App\Console\Commands;

use App\Mcp\AegisMcpServer;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisMcpServe extends Command
{
    protected $signature = 'aegis:mcp:serve {--once : Process a single request and exit}';

    protected $description = 'Serve Aegis MCP over stdio using JSON-RPC 2.0';

    public function __construct(private readonly AegisMcpServer $server)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $input = fopen('php://stdin', 'r');
        $output = fopen('php://stdout', 'w');

        if (! is_resource($input) || ! is_resource($output)) {
            $this->error('Unable to open stdio streams.');

            return CommandStatus::FAILURE;
        }

        $maxRequests = $this->option('once') ? 1 : null;

        $status = $this->serveStreams($input, $output, $maxRequests);

        fclose($input);
        fclose($output);

        return $status;
    }

    public function serveStreams($input, $output, ?int $maxRequests = null): int
    {
        $processed = 0;

        while (($line = fgets($input)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $payload = json_decode($line, true);

            if (! is_array($payload)) {
                fwrite($output, json_encode($this->server->parseError(), JSON_UNESCAPED_SLASHES).PHP_EOL);
            } else {
                $response = $this->server->handleRequest($payload);
                fwrite($output, json_encode($response, JSON_UNESCAPED_SLASHES).PHP_EOL);
            }

            $processed++;

            if ($maxRequests !== null && $processed >= $maxRequests) {
                break;
            }
        }

        return CommandStatus::SUCCESS;
    }
}
