<?php

namespace App\Agent;

class ToolResult
{
    public function __construct(
        public readonly bool $success,
        public readonly mixed $output,
        public readonly ?string $error = null,
    ) {}
}
