<?php

namespace App\Console\Commands;

use App\Plugins\PluginSigner;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisPluginSign extends Command
{
    protected $signature = 'aegis:plugin:sign {path}';

    protected $description = 'Sign a plugin package with the local Ed25519 key';

    public function __construct(private readonly PluginSigner $pluginSigner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        try {
            $result = $this->pluginSigner->signPath($path);

            $this->info('Plugin signed successfully.');
            $this->line('Path: '.$result['path']);
            $this->line('Hash: '.$result['hash']);
            $this->line('Public key: '.$result['public_key']);

            return CommandStatus::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return CommandStatus::FAILURE;
        }
    }
}
