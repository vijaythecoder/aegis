<?php

namespace App\Mcp;

use App\Agent\SystemPromptBuilder;
use App\Models\Conversation;
use App\Tools\ToolRegistry;

class McpPromptProvider
{
    public function __construct(
        private readonly SystemPromptBuilder $systemPromptBuilder,
        private readonly ToolRegistry $toolRegistry,
    ) {}

    public function list(): array
    {
        return [
            [
                'name' => 'aegis://system-prompt',
                'description' => 'Current dynamic system prompt.',
                'arguments' => [
                    [
                        'name' => 'conversation_id',
                        'description' => 'Optional conversation id for scoped preferences.',
                        'required' => false,
                    ],
                ],
            ],
            [
                'name' => 'aegis://agent-status',
                'description' => 'Current server capabilities and configuration.',
                'arguments' => [],
            ],
        ];
    }

    public function get(string $name, array $arguments = []): array
    {
        if ($name === 'aegis://system-prompt') {
            $conversation = null;

            if (isset($arguments['conversation_id'])) {
                $conversation = Conversation::query()->find((int) $arguments['conversation_id']);
            }

            return [
                'description' => 'Aegis system prompt',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => $this->systemPromptBuilder->build($conversation),
                        ],
                    ],
                ],
            ];
        }

        if ($name === 'aegis://agent-status') {
            $status = [
                'name' => config('aegis.name'),
                'version' => config('aegis.version'),
                'tools' => $this->toolRegistry->names(),
                'provider_default' => config('aegis.agent.default_provider'),
                'model_default' => config('aegis.agent.default_model'),
                'mcp' => [
                    'enabled' => config('aegis.mcp.enabled', true),
                    'auth_method' => config('aegis.mcp.auth_method', 'sanctum'),
                    'allowed_tools' => config('aegis.mcp.allowed_tools', ['*']),
                ],
            ];

            $encoded = json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            return [
                'description' => 'Aegis runtime status',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => $encoded === false ? '{}' : $encoded,
                        ],
                    ],
                ],
            ];
        }

        return [
            'description' => 'Prompt not found',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => 'Prompt not found.',
                    ],
                ],
            ],
        ];
    }
}
