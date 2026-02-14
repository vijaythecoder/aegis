<?php

namespace App\Plugins;

use App\Models\Setting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

class PluginManager
{
    public const LOADING_MANIFEST_CONTEXT = 'aegis.plugins.loading_manifest';

    private array $plugins = [];

    private array $enabled = [];

    private array $loaded = [];

    private array $autoloaders = [];

    public function __construct(
        private readonly Application $app,
        private readonly string $pluginsPath,
    ) {
        $this->enabled = $this->readEnabledPlugins();
    }

    public function discover(): array
    {
        $this->plugins = [];

        if (! is_dir($this->pluginsPath)) {
            return $this->plugins;
        }

        foreach (File::directories($this->pluginsPath) as $pluginPath) {
            try {
                $manifest = PluginManifest::fromPath($pluginPath);
                $this->plugins[$manifest->name] = $manifest;
            } catch (InvalidArgumentException) {
            }
        }

        ksort($this->plugins);

        return $this->plugins;
    }

    public function load(string $name): void
    {
        if (isset($this->loaded[$name])) {
            return;
        }

        $manifest = $this->get($name);

        if (! $manifest) {
            throw new InvalidArgumentException("Plugin [{$name}] is not installed.");
        }

        $this->registerAutoloaders($manifest);

        if (! class_exists($manifest->provider)) {
            throw new InvalidArgumentException("Plugin provider [{$manifest->provider}] could not be loaded.");
        }

        $this->app->instance(self::LOADING_MANIFEST_CONTEXT, $manifest);

        try {
            $provider = $this->app->register($manifest->provider);
        } finally {
            $this->app->instance(self::LOADING_MANIFEST_CONTEXT, null);
        }

        $this->loaded[$name] = [
            'manifest' => $manifest,
            'provider' => $provider,
        ];
    }

    public function unload(string $name): void
    {
        if (! isset($this->loaded[$name])) {
            return;
        }

        $manifest = $this->loaded[$name]['manifest'];

        foreach ($manifest->tools as $toolName) {
            $this->app->make(\App\Tools\ToolRegistry::class)->unregister($toolName);
        }

        unset($this->loaded[$name]);
    }

    public function enable(string $name): void
    {
        if (! in_array($name, $this->enabled, true)) {
            $this->enabled[] = $name;
            sort($this->enabled);
        }

        $this->persistEnabledPlugins();
    }

    public function disable(string $name): void
    {
        $this->enabled = array_values(array_filter(
            $this->enabled,
            static fn (string $enabled): bool => $enabled !== $name,
        ));

        $this->persistEnabledPlugins();
        $this->unload($name);
    }

    public function isEnabled(string $name): bool
    {
        return in_array($name, $this->enabled, true);
    }

    public function installed(): array
    {
        return $this->discover();
    }

    public function enabled(): array
    {
        return $this->enabled;
    }

    public function get(string $name): ?PluginManifest
    {
        if ($this->plugins === []) {
            $this->discover();
        }

        return $this->plugins[$name] ?? null;
    }

    public function bootAll(): void
    {
        if (! config('aegis.plugins.auto_discover', true)) {
            return;
        }

        $installed = $this->discover();

        foreach ($this->enabled as $name) {
            if (! isset($installed[$name])) {
                continue;
            }

            $this->load($name);
        }
    }

    private function registerAutoloaders(PluginManifest $manifest): void
    {
        foreach (($manifest->autoload['psr-4'] ?? []) as $namespace => $relativePath) {
            $loader = function (string $class) use ($namespace, $manifest, $relativePath): void {
                if (! str_starts_with($class, (string) $namespace)) {
                    return;
                }

                $relativeClass = substr($class, strlen((string) $namespace));
                $file = rtrim($manifest->path, DIRECTORY_SEPARATOR)
                    .DIRECTORY_SEPARATOR
                    .trim((string) $relativePath, '/\\')
                    .DIRECTORY_SEPARATOR
                    .str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)
                    .'.php';

                if (is_file($file)) {
                    require_once $file;
                }
            };

            spl_autoload_register($loader);
            $this->autoloaders[] = $loader;
        }
    }

    private function readEnabledPlugins(): array
    {
        try {
            $stored = Setting::query()
                ->where('group', 'plugins')
                ->where('key', 'enabled')
                ->value('value');
        } catch (Throwable) {
            $stored = null;
        }

        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);

            if (is_array($decoded)) {
                return array_values(array_filter($decoded, 'is_string'));
            }
        }

        return array_values(array_filter((array) config('aegis.plugins.enabled_plugins', []), 'is_string'));
    }

    private function persistEnabledPlugins(): void
    {
        Setting::query()->updateOrCreate(
            ['group' => 'plugins', 'key' => 'enabled'],
            [
                'value' => json_encode(array_values($this->enabled), JSON_UNESCAPED_SLASHES),
                'is_encrypted' => false,
            ],
        );
    }
}
