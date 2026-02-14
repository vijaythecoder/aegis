<?php

namespace App\Console\Commands;

use App\Messaging\Adapters\SignalAdapter;
use Illuminate\Console\Command;

class AegisSignalSetup extends Command
{
    protected $signature = 'aegis:signal:setup';

    protected $description = 'Guide through Signal adapter setup via signal-cli (experimental)';

    public function handle(): int
    {
        $this->warn('⚠ Signal adapter is EXPERIMENTAL — it uses the unofficial signal-cli tool.');
        $this->newLine();

        $adapter = new SignalAdapter(processRunner: function (string $command): array {
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
        });

        $this->info('Step 1: Checking signal-cli installation...');

        if ($adapter->isSignalCliInstalled()) {
            $this->info('  ✓ signal-cli is installed');
        } else {
            $this->error('  ✗ signal-cli not found');
            $this->newLine();
            $this->line('Install signal-cli:');
            $this->line('  macOS:   brew install signal-cli');
            $this->line('  Linux:   https://github.com/AsamK/signal-cli/releases');
            $this->line('  Requires Java runtime (JRE 17+)');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Step 2: Account configuration');
        $this->line('Set your Signal phone number in .env:');
        $this->line('  AEGIS_SIGNAL_PHONE_NUMBER=+15551234567');
        $this->line('  AEGIS_SIGNAL_ENABLED=true');
        $this->newLine();

        $this->info('Step 3: Register or link your account');
        $this->line('  Register new:  signal-cli -a +YOURNUMBER register');
        $this->line('  Verify:        signal-cli -a +YOURNUMBER verify CODE');
        $this->line('  Link existing: signal-cli link -n "Aegis"');
        $this->newLine();

        $this->info('Signal adapter setup guide complete.');

        return self::SUCCESS;
    }
}
