<?php

namespace App\Console\Commands;

use App\Plugins\PluginSigner;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisPluginKeygen extends Command
{
    protected $signature = 'aegis:plugin:keygen';

    protected $description = 'Generate an Ed25519 keypair for plugin signing';

    public function __construct(private readonly PluginSigner $pluginSigner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->pluginSigner->writeDefaultKeyPair();

            $this->info('Generated plugin signing keypair.');
            $this->line('Public key: '.$result['public_key']);
            $this->line('Public key path: '.$result['public_key_path']);
            $this->line('Secret key path: '.$result['secret_key_path']);

            return CommandStatus::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return CommandStatus::FAILURE;
        }
    }
}
