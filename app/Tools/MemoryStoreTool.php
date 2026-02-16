<?php

namespace App\Tools;

use App\Enums\MemoryType;
use App\Memory\EmbeddingService;
use App\Memory\MemoryService;
use App\Memory\VectorStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class MemoryStoreTool implements Tool
{
    public function __construct(
        protected MemoryService $memoryService,
        protected EmbeddingService $embeddingService,
        protected VectorStore $vectorStore,
    ) {}

    public function name(): string
    {
        return 'memory_store';
    }

    public function requiredPermission(): string
    {
        return 'write';
    }

    public function description(): Stringable|string
    {
        return 'Store important information about the user for recall in future conversations. Use for facts, preferences, and notes worth remembering.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['fact', 'preference', 'note'])->description('Type of memory: "fact" for personal info (name, job, location), "preference" for likes/dislikes (dark mode, language), "note" for project details or important context.')->required(),
            'key' => $schema->string()->description('Short, dot-notation identifier for this memory (e.g., "user.name", "user.preference.theme", "project.aegis.stack"). Used for deduplication — same key overwrites previous value.')->required(),
            'value' => $schema->string()->description('The information to remember. Be specific and concise.')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $typeString = (string) $request->string('type');
        $key = trim((string) $request->string('key'));
        $value = trim((string) $request->string('value'));

        if ($key === '' || $value === '') {
            return 'Memory not stored: key and value are required.';
        }

        $type = MemoryType::tryFrom($typeString);

        if ($type === null) {
            return 'Memory not stored: invalid type. Use "fact", "preference", or "note".';
        }

        $memory = $this->memoryService->store($type, $key, $value, null, 1.0);

        $embedding = $this->embeddingService->embed("{$key}: {$value}");

        if ($embedding !== null) {
            $this->vectorStore->store($embedding, [
                'source_type' => 'memory',
                'source_id' => $memory->id,
                'content_preview' => "{$key}: {$value}",
            ]);
        }

        return "Stored {$type->value}: {$key} = {$value}";
    }
}
