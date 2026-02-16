<?php

namespace App\Tools;

use App\Models\Document;
use App\Rag\RetrievalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class KnowledgeSearchTool implements Tool
{
    public function __construct(
        protected RetrievalService $retrievalService,
    ) {}

    public function name(): string
    {
        return 'knowledge_search';
    }

    public function description(): Stringable|string
    {
        return 'Search the knowledge base for information from uploaded documents (markdown, code, text files). Use this when the user asks about topics from their documents.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search query to find relevant information in uploaded documents.')->required(),
            'limit' => $schema->integer()->description('Maximum number of results to return (default: 5).'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = (string) $request->string('query');
        $limit = $request->integer('limit', 5);

        if (trim($query) === '') {
            return 'No results found. Please provide a search query.';
        }

        $results = $this->retrievalService->retrieve($query, $limit);

        if (empty($results)) {
            return 'No matching documents found in the knowledge base.';
        }

        return collect($results)->map(function (array $result, int $index): string {
            $num = $index + 1;
            $source = $result['document_name'];
            $content = str_replace("\n", ' ', $result['content']);

            return "[{$num}] (from: {$source}) {$content}";
        })->implode("\n\n");
    }

    /**
     * @return string[]
     */
    public static function vectorStoreIds(): array
    {
        try {
            return Document::query()
                ->whereNotNull('vector_store_id')
                ->pluck('vector_store_id')
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
