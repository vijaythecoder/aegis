<?php

namespace App\Console\Commands;

use App\Security\ApiKeyManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisKeyList extends Command
{
    protected $signature = 'aegis:key:list';

    protected $description = 'List provider API key status';

    public function __construct(private readonly ApiKeyManager $apiKeyManager)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = [];

        foreach ($this->apiKeyManager->list() as $provider => $data) {
            $rows[] = [
                $provider,
                $data['name'],
                $data['requires_key']
                    ? ($data['is_set'] ? 'set' : 'not set')
                    : 'n/a',
                $data['masked'] ?? '-',
            ];
        }

        $this->table(['Provider', 'Name', 'Status', 'Masked'], $rows);

        return CommandStatus::SUCCESS;
    }
}
