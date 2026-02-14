<?php

namespace App\Mcp;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Memory;

class McpResourceProvider
{
    public function list(): array
    {
        return [
            [
                'uri' => 'aegis://conversations',
                'name' => 'Conversations',
                'description' => 'List of Aegis conversations.',
                'mimeType' => 'application/json',
            ],
            [
                'uri' => 'aegis://conversations/{id}',
                'name' => 'Conversation Details',
                'description' => 'Messages for a specific conversation.',
                'mimeType' => 'application/json',
            ],
            [
                'uri' => 'aegis://memories',
                'name' => 'Memories',
                'description' => 'Stored Aegis facts and preferences.',
                'mimeType' => 'application/json',
            ],
            [
                'uri' => 'aegis://audit-log',
                'name' => 'Audit Log',
                'description' => 'Recent audit entries.',
                'mimeType' => 'application/json',
            ],
        ];
    }

    public function read(string $uri): array
    {
        if ($uri === 'aegis://conversations') {
            return $this->jsonResource($uri, Conversation::query()
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get([
                    'id',
                    'title',
                    'summary',
                    'model',
                    'provider',
                    'is_archived',
                    'last_message_at',
                    'created_at',
                    'updated_at',
                ])
                ->toArray());
        }

        if (preg_match('#^aegis://conversations/(\d+)$#', $uri, $matches) === 1) {
            $conversation = Conversation::query()
                ->with(['messages' => fn ($query) => $query->orderBy('id')])
                ->find((int) $matches[1]);

            if ($conversation === null) {
                return $this->jsonResource($uri, ['error' => 'Conversation not found.']);
            }

            return $this->jsonResource($uri, [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'summary' => $conversation->summary,
                'model' => $conversation->model,
                'provider' => $conversation->provider,
                'is_archived' => $conversation->is_archived,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'messages' => $conversation->messages->map(fn ($message): array => [
                    'id' => $message->id,
                    'role' => $message->role->value,
                    'content' => $message->content,
                    'tool_name' => $message->tool_name,
                    'tool_call_id' => $message->tool_call_id,
                    'tool_result' => $message->tool_result,
                    'tokens_used' => $message->tokens_used,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->values()->all(),
            ]);
        }

        if ($uri === 'aegis://memories') {
            return $this->jsonResource($uri, Memory::query()
                ->orderByDesc('updated_at')
                ->limit(100)
                ->get([
                    'id',
                    'type',
                    'key',
                    'value',
                    'source',
                    'conversation_id',
                    'confidence',
                    'created_at',
                    'updated_at',
                ])
                ->map(fn (Memory $memory): array => [
                    'id' => $memory->id,
                    'type' => $memory->type->value,
                    'key' => $memory->key,
                    'value' => $memory->value,
                    'source' => $memory->source,
                    'conversation_id' => $memory->conversation_id,
                    'confidence' => $memory->confidence,
                    'created_at' => $memory->created_at?->toIso8601String(),
                    'updated_at' => $memory->updated_at?->toIso8601String(),
                ])
                ->values()
                ->all());
        }

        if ($uri === 'aegis://audit-log') {
            return $this->jsonResource($uri, AuditLog::query()
                ->latest('id')
                ->limit(100)
                ->get([
                    'id',
                    'conversation_id',
                    'action',
                    'tool_name',
                    'parameters',
                    'result',
                    'ip_address',
                    'created_at',
                ])
                ->map(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'conversation_id' => $log->conversation_id,
                    'action' => $log->action,
                    'tool_name' => $log->tool_name,
                    'parameters' => $log->parameters,
                    'result' => $log->result->value,
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])
                ->values()
                ->all());
        }

        return $this->jsonResource($uri, ['error' => 'Resource not found.']);
    }

    private function jsonResource(string $uri, mixed $payload): array
    {
        $text = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'application/json',
                    'text' => $text === false ? '{}' : $text,
                ],
            ],
        ];
    }
}
