<?php

namespace App\Tools;

use App\Memory\EmbeddingService;
use App\Memory\HybridSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class MemoryRecallTool implements Tool
{
    public function __construct(
        protected HybridSearchService $searchService,
        protected EmbeddingService $embeddingService,
    ) {}

    public function name(): string
    {
        return 'memory_recall';
    }

    public function requiredPermission(): string
    {
        return 'read';
    }

    public function description(): Stringable|string
    {
        return 'Search across all past conversations and memories for relevant information.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search query to find relevant memories and past conversations.')->required(),
            'limit' => $schema->integer()->description('Maximum number of results to return (default: 5).'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = (string) $request->string('query');
        $limit = $request->integer('limit', 5);

        if (trim($query) === '') {
            return 'No memories found matching your query.';
        }

        $queryEmbedding = $this->embeddingService->embed($query);

        $results = $this->searchService->search($query, $queryEmbedding, $limit);

        if ($results->isEmpty()) {
            return 'No memories found matching your query.';
        }

        return $results->map(function ($result, $index) {
            $num = $index + 1;
            $preview = str_replace("\n", ' ', $result['content_preview']);

            return "[{$num}] ({$result['source_type']}) {$preview}";
        })->implode("\n");
    }
}
