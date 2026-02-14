<?php

namespace App\Plugins;

use App\Agent\Contracts\ToolInterface;
use App\Agent\ToolResult;
use App\Tools\ToolRegistry;
use Illuminate\Support\ServiceProvider;

abstract class PluginServiceProvider extends ServiceProvider
{
    abstract public function pluginName(): string;

    protected function registerTool(string $abstract): void
    {
        $tool = $this->app->make($abstract);

        if (! $tool instanceof ToolInterface) {
            return;
        }

        $registry = $this->app->make(ToolRegistry::class);
        $manifest = $this->app->bound(PluginManager::LOADING_MANIFEST_CONTEXT)
            ? $this->app->make(PluginManager::LOADING_MANIFEST_CONTEXT)
            : null;

        if (! $manifest instanceof PluginManifest) {
            $registry->register($tool);

            return;
        }

        $sandbox = $this->app->make(PluginSandbox::class);

        $registry->register(new class($tool, $sandbox, $manifest) implements ToolInterface
        {
            public function __construct(
                private readonly ToolInterface $tool,
                private readonly PluginSandbox $sandbox,
                private readonly PluginManifest $manifest,
            ) {}

            public function name(): string
            {
                return $this->tool->name();
            }

            public function description(): string
            {
                return $this->tool->description();
            }

            public function parameters(): array
            {
                return $this->tool->parameters();
            }

            public function execute(array $input): ToolResult
            {
                return $this->sandbox->execute($this->tool, $input, $this->manifest);
            }

            public function requiredPermission(): string
            {
                return $this->tool->requiredPermission();
            }
        });
    }
}
