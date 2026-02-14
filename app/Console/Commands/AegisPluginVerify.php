<?php

namespace App\Console\Commands;

use App\Plugins\PluginVerifier;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisPluginVerify extends Command
{
    protected $signature = 'aegis:plugin:verify {path}';

    protected $description = 'Verify a plugin signature and trust level';

    public function __construct(private readonly PluginVerifier $pluginVerifier)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        try {
            $result = $this->pluginVerifier->verifyPath($path);

            if ($result['status'] === PluginVerifier::STATUS_TAMPERED) {
                $this->error("Plugin [{$path}] failed signature verification.");
                $this->line('Trust level: '.$result['trust_level']);
                $this->line('Hash: '.$result['hash']);

                return CommandStatus::FAILURE;
            }

            if ($result['status'] === PluginVerifier::STATUS_UNSIGNED) {
                $this->warn("Plugin [{$path}] is unsigned.");
            } else {
                $this->info("Plugin [{$path}] signature is valid.");
            }

            $this->line('Trust level: '.$result['trust_level']);
            $this->line('Hash: '.$result['hash']);

            return CommandStatus::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return CommandStatus::FAILURE;
        }
    }
}
