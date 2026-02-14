<?php

namespace App\Agent\Contracts;

use App\Agent\ToolResult;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    public function parameters(): array;

    public function execute(array $input): ToolResult;

    public function requiredPermission(): string;
}
