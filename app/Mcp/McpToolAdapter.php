<?php

namespace App\Mcp;

use App\Agent\Contracts\ToolInterface;
use App\Agent\ToolResult;
use App\Enums\AuditLogResult;
use App\Security\AuditLogger;
use App\Security\PermissionDecision;
use App\Security\PermissionManager;
use App\Tools\ToolRegistry;
use Throwable;

class McpToolAdapter
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly PermissionManager $permissionManager,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function list(array $allowedTools = ['*']): array
    {
        return collect($this->toolRegistry->all())
            ->filter(fn (ToolInterface $tool): bool => $this->isAllowedTool($tool->name(), $allowedTools))
            ->map(fn (ToolInterface $tool): array => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $this->normalizeSchema($tool->parameters()),
            ])
            ->values()
            ->all();
    }

    public function call(string $toolName, array $arguments = [], ?int $conversationId = null): array
    {
        $this->auditLogger->log(
            action: 'mcp.tool.request',
            toolName: $toolName,
            parameters: $arguments,
            result: AuditLogResult::Pending->value,
            conversationId: $conversationId,
        );

        $tool = $this->toolRegistry->get($toolName);

        if (! $tool instanceof ToolInterface) {
            $this->auditLogger->log(
                action: 'mcp.tool.error',
                toolName: $toolName,
                parameters: $arguments,
                result: AuditLogResult::Error->value,
                conversationId: $conversationId,
            );

            return $this->asError("Unknown tool: {$toolName}");
        }

        $scope = $conversationId === null ? 'mcp:global' : 'mcp:conversation:'.$conversationId;
        $decision = $this->permissionManager->check($toolName, $tool->requiredPermission(), [
            ...$arguments,
            'scope' => $scope,
        ]);

        if ($decision !== PermissionDecision::Allowed) {
            $this->auditLogger->log(
                action: 'mcp.tool.denied',
                toolName: $toolName,
                parameters: $arguments,
                result: AuditLogResult::Denied->value,
                conversationId: $conversationId,
            );

            $message = $decision === PermissionDecision::NeedsApproval
                ? 'Tool execution requires approval.'
                : 'Tool execution denied by security policy.';

            return $this->asError($message);
        }

        $this->auditLogger->log(
            action: 'mcp.tool.allowed',
            toolName: $toolName,
            parameters: $arguments,
            result: AuditLogResult::Allowed->value,
            conversationId: $conversationId,
        );

        try {
            $result = $tool->execute($arguments);

            $this->auditLogger->log(
                action: $result->success ? 'mcp.tool.executed' : 'mcp.tool.error',
                toolName: $toolName,
                parameters: [
                    ...$arguments,
                    'tool_result' => [
                        'success' => $result->success,
                        'output' => $result->output,
                        'error' => $result->error,
                    ],
                ],
                result: $result->success ? AuditLogResult::Allowed->value : AuditLogResult::Error->value,
                conversationId: $conversationId,
            );

            return $this->toMcpResult($result);
        } catch (Throwable $exception) {
            $this->auditLogger->log(
                action: 'mcp.tool.error',
                toolName: $toolName,
                parameters: [
                    ...$arguments,
                    'exception' => $exception->getMessage(),
                ],
                result: AuditLogResult::Error->value,
                conversationId: $conversationId,
            );

            return $this->asError($exception->getMessage());
        }
    }

    private function toMcpResult(ToolResult $result): array
    {
        if (! $result->success) {
            return $this->asError($result->error ?? 'Tool execution failed.');
        }

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $this->stringifyOutput($result->output),
                ],
            ],
        ];
    }

    private function asError(string $message): array
    {
        return [
            'isError' => true,
            'content' => [
                [
                    'type' => 'text',
                    'text' => $message,
                ],
            ],
        ];
    }

    private function stringifyOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        if (is_scalar($output) || $output === null) {
            return (string) $output;
        }

        $encoded = json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $encoded === false ? 'Unable to encode tool result.' : $encoded;
    }

    private function normalizeSchema(array $parameters): array
    {
        $hasJsonSchemaShape = isset($parameters['type']) || isset($parameters['properties']);

        if ($hasJsonSchemaShape) {
            $schema = $parameters;
            $schema['type'] = 'object';
            $schema['properties'] = $schema['properties'] ?? [];
            $schema['required'] = array_values(array_filter((array) ($schema['required'] ?? []), 'is_string'));

            return $schema;
        }

        $properties = [];

        foreach ($parameters as $name => $type) {
            if (! is_string($name)) {
                continue;
            }

            $properties[$name] = [
                'type' => $this->normalizeType($type),
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
        ];
    }

    private function normalizeType(mixed $type): string
    {
        if (! is_string($type)) {
            return 'string';
        }

        return match (strtolower($type)) {
            'int', 'integer' => 'integer',
            'float', 'double', 'number' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            default => 'string',
        };
    }

    private function isAllowedTool(string $toolName, array $allowedTools): bool
    {
        if (in_array('*', $allowedTools, true)) {
            return true;
        }

        return in_array($toolName, $allowedTools, true);
    }
}
