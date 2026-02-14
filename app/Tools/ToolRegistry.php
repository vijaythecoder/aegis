<?php

namespace App\Tools;

use App\Agent\Contracts\ToolInterface;
use Illuminate\Support\Facades\App;
use Symfony\Component\Finder\Finder;

class ToolRegistry
{
    private array $tools = [];

    public function __construct(?string $toolsPath = null)
    {
        $this->discover($toolsPath ?? app_path('Tools'));
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
        ksort($this->tools);
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
        $finder->files()->in($toolsPath)->name('*.php');

        foreach ($finder as $file) {
            $class = 'App\\Tools\\'.$file->getBasename('.php');

            if (! class_exists($class) || ! is_subclass_of($class, ToolInterface::class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $instance = App::make($class);
            $this->register($instance);
        }
    }
}
