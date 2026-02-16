<?php

namespace App\Tools;

use App\Agent\Contracts\ToolInterface;
use Illuminate\Support\Facades\App;
use Laravel\Ai\Contracts\Tool;
use Symfony\Component\Finder\Finder;

class ToolRegistry
{
    /** @var array<string, Tool|ToolInterface> */
    private array $tools = [];

    public function __construct(?string $toolsPath = null)
    {
        $this->discover($toolsPath ?? app_path('Tools'));
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function get(string $name): Tool|ToolInterface|null
    {
        return $this->tools[$name] ?? null;
    }

    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Register a tool. Accepts both SDK Tool and legacy ToolInterface.
     *
     * New SDK usage:  register('name', $sdkTool)
     * Legacy usage:   register($legacyTool)
     */
    public function register(string|ToolInterface $nameOrTool, ?Tool $tool = null): void
    {
        if ($nameOrTool instanceof ToolInterface) {
            $this->tools[$nameOrTool->name()] = $nameOrTool;
            ksort($this->tools);

            return;
        }

        if ($tool !== null) {
            $this->tools[$nameOrTool] = $tool;
            ksort($this->tools);
        }
    }

    public function unregister(string $name): void
    {
        unset($this->tools[$name]);
    }

    private function discover(string $toolsPath): void
    {
        if (! is_dir($toolsPath)) {
            return;
        }

        $finder = new Finder;
        $finder->files()->in($toolsPath)->name('*.php')->depth(0);

        foreach ($finder as $file) {
            $class = 'App\\Tools\\'.$file->getBasename('.php');

            if (! class_exists($class) || ! is_subclass_of($class, Tool::class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            try {
                $instance = App::make($class);
                if (method_exists($instance, 'name')) {
                    $this->register($instance->name(), $instance);
                }
            } catch (\Throwable) {
            }
        }
    }
}
