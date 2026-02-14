<?php

namespace App\Mcp;

use Closure;
use Illuminate\Database\QueryException;

class AegisMcpServer
{
    public function __construct(
        private readonly McpToolAdapter $toolAdapter,
        private readonly McpResourceProvider $resourceProvider,
        private readonly McpPromptProvider $promptProvider,
        private readonly ?Closure $sanctumTokenValidator = null,
    ) {}

    public function handleRequest(array $request): array
    {
        $id = $request['id'] ?? null;

        if (($request['jsonrpc'] ?? null) !== '2.0') {
            return $this->error($id, -32600, 'Invalid Request');
        }

        $method = $request['method'] ?? null;

        if (! is_string($method) || $method === '') {
            return $this->error($id, -32600, 'Invalid Request');
        }

        $params = $request['params'] ?? [];
        if (! is_array($params)) {
            return $this->error($id, -32602, 'Invalid params');
        }

        if ($method !== 'initialize') {
            $authorized = $this->authorize($params);
            if ($authorized !== true) {
                return $this->error($id, -32001, $authorized);
            }
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => (string) ($params['protocolVersion'] ?? '2024-11-05'),
                'capabilities' => [
                    'tools' => (object) [],
                    'resources' => (object) [],
                    'prompts' => (object) [],
                ],
                'serverInfo' => [
                    'name' => (string) config('aegis.name', 'Aegis').'-MCP',
                    'version' => (string) config('aegis.version', '0.1.0'),
                ],
            ]),
            'tools/list' => $this->result($id, [
                'tools' => $this->toolAdapter->list($this->allowedTools()),
            ]),
            'tools/call' => $this->handleToolCall($id, $params),
            'resources/list' => $this->result($id, [
                'resources' => $this->resourceProvider->list(),
            ]),
            'resources/read' => $this->handleResourceRead($id, $params),
            'prompts/list' => $this->result($id, [
                'prompts' => $this->promptProvider->list(),
            ]),
            'prompts/get' => $this->handlePromptGet($id, $params),
            default => $this->error($id, -32601, 'Method not found'),
        };
    }

    private function handleToolCall(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || $name === '') {
            return $this->error($id, -32602, 'Invalid params: name is required');
        }

        if (! $this->isAllowedTool($name)) {
            return $this->error($id, -32003, 'Tool is not allowed by MCP configuration.');
        }

        $arguments = $params['arguments'] ?? [];
        if (! is_array($arguments)) {
            return $this->error($id, -32602, 'Invalid params: arguments must be an object');
        }

        $conversationId = isset($params['conversation_id']) ? (int) $params['conversation_id'] : null;

        return $this->result($id, $this->toolAdapter->call($name, $arguments, $conversationId));
    }

    private function handleResourceRead(mixed $id, array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri) || $uri === '') {
            return $this->error($id, -32602, 'Invalid params: uri is required');
        }

        return $this->result($id, $this->resourceProvider->read($uri));
    }

    private function handlePromptGet(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || $name === '') {
            return $this->error($id, -32602, 'Invalid params: name is required');
        }

        $arguments = $params['arguments'] ?? [];

        if (! is_array($arguments)) {
            return $this->error($id, -32602, 'Invalid params: arguments must be an object');
        }

        return $this->result($id, $this->promptProvider->get($name, $arguments));
    }

    private function result(mixed $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    public function parseError(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32700,
                'message' => 'Parse error',
            ],
        ];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    private function allowedTools(): array
    {
        return array_values(array_filter((array) config('aegis.mcp.allowed_tools', ['*']), 'is_string'));
    }

    private function isAllowedTool(string $toolName): bool
    {
        $allowedTools = $this->allowedTools();

        return in_array('*', $allowedTools, true) || in_array($toolName, $allowedTools, true);
    }

    private function authorize(array $params): true|string
    {
        if (! (bool) config('aegis.mcp.enabled', true)) {
            return 'MCP server is disabled.';
        }

        $method = (string) config('aegis.mcp.auth_method', 'sanctum');

        if ($method === 'none') {
            return true;
        }

        if ($method !== 'sanctum') {
            return 'Unsupported MCP auth method.';
        }

        $token = $params['auth_token'] ?? $params['token'] ?? null;
        if (! is_string($token) || trim($token) === '') {
            return 'Auth token is required.';
        }

        if (($this->sanctumTokenValidator) instanceof Closure) {
            return ($this->sanctumTokenValidator)($token) ? true : 'Invalid auth token.';
        }

        $sanctumTokenClass = 'Laravel\\Sanctum\\PersonalAccessToken';

        if (! class_exists($sanctumTokenClass)) {
            return 'Sanctum is not installed.';
        }

        try {
            $model = $sanctumTokenClass::findToken($token);

            return $model === null ? 'Invalid auth token.' : true;
        } catch (QueryException) {
            return 'Sanctum token storage is not ready.';
        }
    }
}
