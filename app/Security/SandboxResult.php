<?php

namespace App\Security;

readonly class SandboxResult
{
    public function __construct(
        public bool $success,
        public ?string $output,
        public ?string $error,
    ) {}
}
