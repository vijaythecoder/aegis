<?php

use App\Console\Commands\AegisMcpServe;
use App\Enums\AuditLogResult;
use App\Enums\MessageRole;
use App\Mcp\AegisMcpServer;
use App\Mcp\McpPromptProvider;
use App\Mcp\McpResourceProvider;
use App\Mcp\McpToolAdapter;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mcpServerForTests(?Closure $tokenValidator = null): AegisMcpServer
{
    return new AegisMcpServer(
        app(McpToolAdapter::class),
        app(McpResourceProvider::class),
        app(McpPromptProvider::class),
        $tokenValidator,
    );
}

it('returns MCP initialize capabilities', function () {
    config()->set('aegis.mcp.auth_method', 'none');

    $response = mcpServerForTests()->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2024-11-05'],
    ]);

    expect($response['result']['protocolVersion'])->toBe('2024-11-05')
        ->and($response['result']['capabilities'])->toBeArray()
        ->and($response['result']['serverInfo']['name'])->toContain('Aegis');
});

it('lists Aegis tools in MCP format', function () {
    config()->set('aegis.mcp.auth_method', 'none');

    $response = mcpServerForTests()->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
    ]);

    $tools = collect($response['result']['tools']);
    $fileRead = $tools->firstWhere('name', 'file_read');

    expect($tools->pluck('name')->all())->toContain('file_read', 'file_write', 'shell', 'browser')
        ->and($fileRead)->toBeArray()
        ->and($fileRead['inputSchema']['type'])->toBe('object')
        ->and($fileRead['inputSchema']['properties']['path']['type'])->toBe('string');
});

it('executes allowed MCP tool calls and writes audit logs', function () {
    config()->set('aegis.mcp.auth_method', 'none');

    $path = storage_path('app/mcp/readable.txt');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, 'mcp-ok');

    $response = mcpServerForTests()->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'file_read',
            'arguments' => ['path' => $path],
        ],
    ]);

    expect($response['result']['content'][0]['text'])->toBe('mcp-ok')
        ->and(AuditLog::query()->where('action', 'mcp.tool.request')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'mcp.tool.executed')->exists())->toBeTrue();
});

it('enforces permissions for MCP tool calls', function () {
    config()->set('aegis.mcp.auth_method', 'none');

    $response = mcpServerForTests()->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/call',
        'params' => [
            'name' => 'shell',
            'arguments' => ['command' => 'php -v && whoami'],
        ],
    ]);

    expect($response['result']['isError'])->toBeTrue()
        ->and($response['result']['content'][0]['text'])->toContain('denied')
        ->and(AuditLog::query()->where('action', 'mcp.tool.denied')->exists())->toBeTrue();
});

it('serves MCP resources and prompts', function () {
    config()->set('aegis.mcp.auth_method', 'none');

    $conversation = Conversation::factory()->create(['title' => 'MCP convo']);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => 'Hello from MCP',
    ]);
    Memory::factory()->create([
        'conversation_id' => $conversation->id,
        'type' => 'preference',
        'key' => 'editor',
        'value' => 'vim',
    ]);
    AuditLog::factory()->create([
        'conversation_id' => $conversation->id,
        'action' => 'mcp.tool.request',
        'tool_name' => 'file_read',
        'result' => AuditLogResult::Pending,
    ]);

    $resourceResponse = mcpServerForTests()->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 5,
        'method' => 'resources/read',
        'params' => ['uri' => 'aegis://conversations/'.$conversation->id],
    ]);

    $promptResponse = mcpServerForTests()->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 6,
        'method' => 'prompts/get',
        'params' => [
            'name' => 'aegis://system-prompt',
            'arguments' => ['conversation_id' => $conversation->id],
        ],
    ]);

    expect($resourceResponse['result']['contents'][0]['text'])->toContain('Hello from MCP')
        ->and($promptResponse['result']['messages'][0]['content']['text'])->toContain('You are');
});

it('requires sanctum auth token when configured', function () {
    config()->set('aegis.mcp.auth_method', 'sanctum');

    $response = mcpServerForTests(fn (string $token): bool => $token === 'valid-token')->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 7,
        'method' => 'tools/list',
        'params' => ['auth_token' => 'invalid-token'],
    ]);

    $allowed = mcpServerForTests(fn (string $token): bool => $token === 'valid-token')->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 8,
        'method' => 'tools/list',
        'params' => ['auth_token' => 'valid-token'],
    ]);

    expect($response['error']['code'])->toBe(-32001)
        ->and($allowed['result']['tools'])->toBeArray();
});

it('handles stdio JSON-RPC transport in command', function () {
    config()->set('aegis.mcp.auth_method', 'none');

    $command = app(AegisMcpServe::class);

    $input = fopen('php://temp', 'r+');
    $output = fopen('php://temp', 'w+');

    fwrite($input, '{"jsonrpc":"2.0","id":9,"method":"initialize","params":{"protocolVersion":"2024-11-05"}}'.PHP_EOL);
    fwrite($input, 'not-json'.PHP_EOL);
    rewind($input);

    $exitCode = $command->serveStreams($input, $output, 2);

    rewind($output);
    $written = stream_get_contents($output);

    fclose($input);
    fclose($output);

    expect($exitCode)->toBe(0)
        ->and($written)->toContain('"id":9')
        ->and($written)->toContain('"code":-32700');
});
