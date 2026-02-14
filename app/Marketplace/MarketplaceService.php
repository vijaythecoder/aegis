<?php

namespace App\Marketplace;

use App\Plugins\PluginInstaller;
use App\Plugins\PluginManager;
use App\Plugins\PluginManifest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class MarketplaceService
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginInstaller $pluginInstaller,
        private readonly PluginManager $pluginManager,
    ) {}

    public function search(?string $query = null)
    {
        $this->registry->sync();

        return $this->registry->search($query);
    }

    public function install(string $name): PluginManifest
    {
        $plugin = $this->registry->getPlugin($name);

        if (! $plugin) {
            $this->registry->sync(true);
            $plugin = $this->registry->getPlugin($name);
        }

        if (! $plugin) {
            throw new InvalidArgumentException("Marketplace plugin [{$name}] was not found.");
        }

        $response = Http::timeout(20)->get($this->endpoint('/plugins/'.urlencode($name).'/download'));

        if (! $response->successful()) {
            throw new InvalidArgumentException("Failed to fetch install source for [{$name}].");
        }

        $source = (string) $response->json('source');

        if ($source === '') {
            throw new InvalidArgumentException("Marketplace did not provide an install source for [{$name}].");
        }

        $manifest = $this->pluginInstaller->install($source);
        $this->pluginManager->discover();
        $this->pluginManager->enable($manifest->name);

        return $manifest;
    }

    public function checkUpdates(): array
    {
        $this->registry->sync();
        $updates = [];

        foreach ($this->pluginManager->installed() as $installed) {
            $market = $this->registry->getPlugin($installed->name);

            if (! $market) {
                continue;
            }

            if (version_compare($market->version, $installed->version, '>')) {
                $updates[] = [
                    'name' => $installed->name,
                    'installed_version' => $installed->version,
                    'latest_version' => $market->version,
                ];
            }
        }

        return $updates;
    }

    public function publish(string $path): array
    {
        $manifest = PluginManifest::fromPath($path);

        $response = Http::timeout(20)->post($this->endpoint('/plugins'), [
            'name' => $manifest->name,
            'version' => $manifest->version,
            'description' => $manifest->description,
            'author' => $manifest->author,
            'provider' => $manifest->provider,
            'permissions' => $manifest->permissions,
            'tools' => $manifest->tools,
        ]);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Marketplace publish failed.');
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('aegis.marketplace.registry_url'), '/').$path;
    }
}
