<?php

namespace App\Plugins;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

class PluginInstaller
{
    private array $lastVerification = [];

    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly PluginVerifier $pluginVerifier,
    ) {}

    public function install(string $source): PluginManifest
    {
        $pluginsPath = config('aegis.plugins.path', base_path('plugins'));
        if (! is_dir($pluginsPath)) {
            File::makeDirectory($pluginsPath, 0755, true);
        }

        if ($this->isGitSource($source)) {
            $tmp = storage_path('app/plugins/install-'.bin2hex(random_bytes(8)));

            try {
                $process = new Process(['git', 'clone', '--depth=1', $source, $tmp]);
                $process->run();

                if (! $process->isSuccessful()) {
                    throw new InvalidArgumentException('Plugin install failed: '.$process->getErrorOutput());
                }

                $manifest = PluginManifest::fromPath($tmp);
                $this->enforceVerification($tmp, $manifest);
                File::copyDirectory($tmp, $pluginsPath.DIRECTORY_SEPARATOR.$manifest->name);

                return $manifest;
            } finally {
                if (is_dir($tmp)) {
                    File::deleteDirectory($tmp);
                }
            }
        }

        if (! is_dir($source)) {
            throw new InvalidArgumentException("Plugin source [{$source}] does not exist.");
        }

        $manifest = PluginManifest::fromPath($source);
        $this->enforceVerification($source, $manifest);
        File::copyDirectory($source, $pluginsPath.DIRECTORY_SEPARATOR.$manifest->name);

        return $manifest;
    }

    public function lastVerification(): array
    {
        return $this->lastVerification;
    }

    public function remove(string $name): bool
    {
        $pluginPath = rtrim((string) config('aegis.plugins.path', base_path('plugins')), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$name;

        if (! is_dir($pluginPath)) {
            return false;
        }

        $this->pluginManager->disable($name);

        return File::deleteDirectory($pluginPath);
    }

    private function isGitSource(string $source): bool
    {
        return str_starts_with($source, 'https://')
            || str_starts_with($source, 'http://')
            || str_starts_with($source, 'git@')
            || str_ends_with($source, '.git');
    }

    private function enforceVerification(string $pluginPath, PluginManifest $manifest): void
    {
        $verification = $this->pluginVerifier->verifyPath($pluginPath);
        $this->lastVerification = $verification;

        if ($verification['status'] === PluginVerifier::STATUS_TAMPERED) {
            throw new InvalidArgumentException(
                "Plugin [{$manifest->name}] failed signature verification and cannot be installed.",
            );
        }

        if ($verification['status'] === PluginVerifier::STATUS_UNSIGNED) {
            logger()->warning("Installing unsigned plugin [{$manifest->name}].", [
                'plugin' => $manifest->name,
                'path' => $pluginPath,
            ]);
        }
    }
}
