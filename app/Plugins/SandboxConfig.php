<?php

namespace App\Plugins;

class SandboxConfig
{
    public function __construct(
        private readonly string $mode,
        private readonly int $timeoutSeconds,
        private readonly int $memoryLimitMb,
        private readonly string $tempPath,
        private readonly string $dockerImage,
    ) {}

    public static function fromConfig(): self
    {
        $mode = strtolower((string) config('aegis.plugins.sandbox_mode', 'process'));
        $timeoutSeconds = max(1, (int) config('aegis.plugins.sandbox.timeout', 30));
        $memoryLimitMb = max(32, (int) config('aegis.plugins.sandbox.memory_limit_mb', 128));
        $tempPath = (string) config('aegis.plugins.sandbox.temp_path', storage_path('app/plugins/sandbox'));
        $dockerImage = (string) config('aegis.plugins.sandbox.docker.image', 'php:8.2-cli');

        return new self(
            mode: $mode,
            timeoutSeconds: $timeoutSeconds,
            memoryLimitMb: $memoryLimitMb,
            tempPath: $tempPath,
            dockerImage: $dockerImage,
        );
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function memoryLimitMb(): int
    {
        return $this->memoryLimitMb;
    }

    public function tempPath(): string
    {
        return $this->tempPath;
    }

    public function dockerImage(): string
    {
        return $this->dockerImage;
    }
}
