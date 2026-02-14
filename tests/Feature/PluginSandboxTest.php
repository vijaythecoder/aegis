<?php

use App\Agent\Contracts\ToolInterface;
use App\Plugins\PluginManager;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('executes plugin tools in a separate php process', function () {
    $pluginsPath = sandboxPluginsPath();
    $tempPath = sandboxTempPath();

    configureSandbox(
        mode: 'process',
        timeoutSeconds: 30,
        memoryLimitMb: 128,
        tempPath: $tempPath,
    );

    $fixture = createSandboxPluginFixture(
        pluginsPath: $pluginsPath,
        permissions: ['read'],
        executeBody: <<<'PHP'
return new ToolResult(true, [
    'sandbox_pid' => getmypid(),
]);
PHP,
    );

    $tool = loadSandboxTool($pluginsPath, $fixture['pluginName'], $fixture['toolName']);
    $result = $tool->execute([]);

    expect($result->success)->toBeTrue()
        ->and($result->output)->toBeArray()
        ->and((int) ($result->output['sandbox_pid'] ?? 0))->toBeGreaterThan(0)
        ->and((int) ($result->output['sandbox_pid'] ?? 0))->not->toBe(getmypid());
});

it('blocks shell usage when plugin does not declare shell permission', function () {
    Log::spy();

    $pluginsPath = sandboxPluginsPath();
    $tempPath = sandboxTempPath();

    configureSandbox(tempPath: $tempPath);

    $fixture = createSandboxPluginFixture(
        pluginsPath: $pluginsPath,
        permissions: ['read'],
        executeBody: <<<'PHP'
$command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('echo "sandbox";');
$output = shell_exec($command);

return new ToolResult(true, trim((string) $output));
PHP,
    );

    $tool = loadSandboxTool($pluginsPath, $fixture['pluginName'], $fixture['toolName']);
    $result = $tool->execute([]);

    expect($result->success)->toBeFalse()
        ->and(strtolower((string) $result->error))->toContain('required permissions')
        ->and(strtolower((string) $result->error))->toContain('shell');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'aegis.plugin.sandbox.permission_denied'
            && in_array('shell', (array) ($context['missing_permissions'] ?? []), true));
});

it('blocks network usage when plugin does not declare network permission', function () {
    Log::spy();

    $pluginsPath = sandboxPluginsPath();
    $tempPath = sandboxTempPath();

    configureSandbox(tempPath: $tempPath);

    $fixture = createSandboxPluginFixture(
        pluginsPath: $pluginsPath,
        permissions: ['read'],
        executeBody: <<<'PHP'
$response = file_get_contents('https://example.com');

return new ToolResult(true, is_string($response) ? strlen($response) : 0);
PHP,
    );

    $tool = loadSandboxTool($pluginsPath, $fixture['pluginName'], $fixture['toolName']);
    $result = $tool->execute([]);

    expect($result->success)->toBeFalse()
        ->and(strtolower((string) $result->error))->toContain('required permissions')
        ->and(strtolower((string) $result->error))->toContain('network');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'aegis.plugin.sandbox.permission_denied'
            && in_array('network', (array) ($context['missing_permissions'] ?? []), true));
});

it('enforces filesystem restriction to plugin and sandbox temp paths', function () {
    $pluginsPath = sandboxPluginsPath();
    $tempPath = sandboxTempPath();

    configureSandbox(tempPath: $tempPath);

    $fixture = createSandboxPluginFixture(
        pluginsPath: $pluginsPath,
        permissions: ['filesystem'],
        executeBody: <<<'PHP'
$path = (string) ($input['path'] ?? '');

if ($path === '') {
    return new ToolResult(false, null, 'Path is required.');
}

return new ToolResult(true, (string) file_get_contents($path));
PHP,
    );

    File::put($fixture['pluginPath'].'/allowed.txt', 'allowed-value');

    $tool = loadSandboxTool($pluginsPath, $fixture['pluginName'], $fixture['toolName']);
    $allowed = $tool->execute(['path' => $fixture['pluginPath'].'/allowed.txt']);
    $blocked = $tool->execute(['path' => '/etc/hosts']);

    expect($allowed->success)->toBeTrue()
        ->and($allowed->output)->toBe('allowed-value')
        ->and($blocked->success)->toBeFalse()
        ->and(strtolower((string) $blocked->error))->toContain('outside sandbox paths');
});

it('terminates long-running plugin execution when timeout is exceeded', function () {
    $pluginsPath = sandboxPluginsPath();
    $tempPath = sandboxTempPath();

    configureSandbox(
        timeoutSeconds: 1,
        tempPath: $tempPath,
    );

    $fixture = createSandboxPluginFixture(
        pluginsPath: $pluginsPath,
        permissions: ['read'],
        executeBody: <<<'PHP'
sleep((int) ($input['seconds'] ?? 2));

return new ToolResult(true, 'done');
PHP,
    );

    $tool = loadSandboxTool($pluginsPath, $fixture['pluginName'], $fixture['toolName']);
    $result = $tool->execute(['seconds' => 3]);

    expect($result->success)->toBeFalse()
        ->and(strtolower((string) $result->error))->toContain('timed out');
});

it('enforces memory limits for plugin execution', function () {
    $pluginsPath = sandboxPluginsPath();
    $tempPath = sandboxTempPath();

    configureSandbox(
        memoryLimitMb: 64,
        tempPath: $tempPath,
    );

    $fixture = createSandboxPluginFixture(
        pluginsPath: $pluginsPath,
        permissions: ['read'],
        executeBody: <<<'PHP'
$payload = str_repeat('a', 96 * 1024 * 1024);

return new ToolResult(true, strlen($payload));
PHP,
    );

    $tool = loadSandboxTool($pluginsPath, $fixture['pluginName'], $fixture['toolName']);
    $result = $tool->execute([]);

    // PHP OOM kills process without a clean "memory" error message,
    // so we verify the execution failed (memory limit IS enforced via -d memory_limit)
    expect($result->success)->toBeFalse()
        ->and((string) $result->error)->not->toBeEmpty();
});

it('keeps plugin tools executable when docker mode is configured', function () {
    $pluginsPath = sandboxPluginsPath();
    $tempPath = sandboxTempPath();

    configureSandbox(
        mode: 'docker',
        timeoutSeconds: 30,
        memoryLimitMb: 128,
        tempPath: $tempPath,
    );

    $fixture = createSandboxPluginFixture(
        pluginsPath: $pluginsPath,
        permissions: ['read'],
        executeBody: <<<'PHP'
return new ToolResult(true, 'docker-config-compatible');
PHP,
    );

    $tool = loadSandboxTool($pluginsPath, $fixture['pluginName'], $fixture['toolName']);
    $result = $tool->execute([]);

    expect($result->success)->toBeTrue()
        ->and($result->output)->toBe('docker-config-compatible');
});

function configureSandbox(
    string $mode = 'process',
    int $timeoutSeconds = 30,
    int $memoryLimitMb = 128,
    ?string $tempPath = null,
): void {
    $resolvedTempPath = $tempPath ?? sandboxTempPath();

    File::deleteDirectory($resolvedTempPath);
    File::makeDirectory($resolvedTempPath, 0755, true);

    config()->set('aegis.plugins.sandbox_mode', $mode);
    config()->set('aegis.plugins.sandbox.timeout', $timeoutSeconds);
    config()->set('aegis.plugins.sandbox.memory_limit_mb', $memoryLimitMb);
    config()->set('aegis.plugins.sandbox.temp_path', $resolvedTempPath);
}

function sandboxPluginsPath(): string
{
    static $counter = 0;
    $counter++;

    $pluginsPath = base_path('storage/framework/testing/plugins-sandbox-'.$counter);

    File::deleteDirectory($pluginsPath);
    File::makeDirectory($pluginsPath, 0755, true);

    return $pluginsPath;
}

function sandboxTempPath(): string
{
    static $counter = 0;
    $counter++;

    return base_path('storage/framework/testing/sandbox-temp-'.$counter);
}

function loadSandboxTool(string $pluginsPath, string $pluginName, string $toolName): ToolInterface
{
    $manager = app()->make(PluginManager::class, ['pluginsPath' => $pluginsPath]);
    $manager->discover();
    $manager->load($pluginName);

    $tool = app(ToolRegistry::class)->get($toolName);

    expect($tool)->toBeInstanceOf(ToolInterface::class);

    return $tool;
}

function createSandboxPluginFixture(
    string $pluginsPath,
    array $permissions,
    string $executeBody,
    string $requiredPermission = 'read',
): array {
    static $counter = 0;
    $counter++;

    $suffix = (string) $counter;
    $pluginName = 'sandbox-plugin-'.$suffix;
    $toolName = 'sandbox_tool_'.$suffix;
    $namespace = 'SandboxPlugin'.$suffix;
    $providerClass = 'SandboxServiceProvider'.$suffix;
    $toolClass = 'SandboxTool'.$suffix;
    $pluginPath = $pluginsPath.'/'.$pluginName;

    File::makeDirectory($pluginPath.'/src', 0755, true);

    File::put($pluginPath.'/plugin.json', json_encode([
        'name' => $pluginName,
        'version' => '1.0.0',
        'description' => 'Sandbox plugin fixture',
        'author' => 'Tests',
        'permissions' => array_values($permissions),
        'provider' => "{$namespace}\\{$providerClass}",
        'tools' => [$toolName],
        'autoload' => [
            'psr-4' => [
                "{$namespace}\\" => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    File::put($pluginPath.'/src/'.$providerClass.'.php', "<?php\n\nnamespace {$namespace};\n\nuse App\\Plugins\\PluginServiceProvider;\n\nclass {$providerClass} extends PluginServiceProvider\n{\n    public function pluginName(): string\n    {\n        return '{$pluginName}';\n    }\n\n    public function boot(): void\n    {\n        \$this->registerTool({$toolClass}::class);\n    }\n}\n");

    $normalizedBody = implode("\n", array_map(
        static fn (string $line): string => '        '.rtrim($line),
        explode("\n", trim($executeBody)),
    ));

    File::put($pluginPath.'/src/'.$toolClass.'.php', "<?php\n\nnamespace {$namespace};\n\nuse App\\Agent\\ToolResult;\nuse App\\Tools\\BaseTool;\n\nclass {$toolClass} extends BaseTool\n{\n    public function name(): string\n    {\n        return '{$toolName}';\n    }\n\n    public function description(): string\n    {\n        return 'Sandbox fixture tool';\n    }\n\n    public function requiredPermission(): string\n    {\n        return '{$requiredPermission}';\n    }\n\n    public function parameters(): array\n    {\n        return [\n            'type' => 'object',\n            'properties' => [\n                'path' => ['type' => 'string'],\n                'seconds' => ['type' => 'integer'],\n            ],\n        ];\n    }\n\n    public function execute(array \$input): ToolResult\n    {\n{$normalizedBody}\n    }\n}\n");

    return [
        'pluginName' => $pluginName,
        'toolName' => $toolName,
        'pluginPath' => $pluginPath,
    ];
}
