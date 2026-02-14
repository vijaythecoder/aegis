<?php

namespace App\Tools;

use App\Agent\ToolResult;

class WebSearchTool extends BaseTool
{
    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return 'Search the web for external information.';
    }

    public function parameters(): array
    {
        return [
            'query' => 'string',
        ];
    }

    public function requiredPermission(): string
    {
        return 'network';
    }

    public function execute(array $input): ToolResult
    {
        $query = (string) ($input['query'] ?? '');

        return new ToolResult(true, "Web search not yet implemented. Query: {$query}");
    }
}
