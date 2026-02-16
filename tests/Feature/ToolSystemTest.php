<?php

use App\Tools\FileListTool;
use App\Tools\FileReadTool;
use App\Tools\FileWriteTool;
use App\Tools\ShellTool;
use App\Tools\ToolRegistry;
use App\Tools\WebSearchTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

it('auto-discovers all concrete tools in registry', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->names())
        ->toContain('file_read', 'file_write', 'file_list', 'shell', 'web_search');
});

it('returns tools by name and null for unknown tools', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->get('file_read'))->toBeInstanceOf(FileReadTool::class)
        ->and($registry->get('missing_tool'))->toBeNull();
});

it('reads file contents for allowed paths', function () {
    $path = storage_path('app/tools/read-ok.txt');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, 'hello-aegis');

    $tool = app(FileReadTool::class);
    $result = $tool->handle(new Request(['path' => $path]));

    expect($result)->toBe('hello-aegis');
});

it('rejects reading /etc/passwd', function () {
    $tool = app(FileReadTool::class);
    $result = $tool->handle(new Request(['path' => '/etc/passwd']));

    expect((string) $result)->toContain('Error:')
        ->and((string) $result)->toContain('not allowed');
});

it('rejects reading /etc/shadow', function () {
    $tool = app(FileReadTool::class);
    $result = $tool->handle(new Request(['path' => '/etc/shadow']));

    expect((string) $result)->toContain('Error:')
        ->and((string) $result)->toContain('not allowed');
});

it('writes file contents in allowed paths and creates directories', function () {
    $path = storage_path('app/tools/nested/write-ok.txt');

    $tool = app(FileWriteTool::class);
    $result = $tool->handle(new Request(['path' => $path, 'content' => 'written-content']));

    expect((string) $result)->toContain('Wrote')
        ->and(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toBe('written-content');
});

it('rejects writing outside allowed paths', function () {
    $tool = app(FileWriteTool::class);
    $result = $tool->handle(new Request(['path' => '/tmp/aegis-disallowed-write.txt', 'content' => 'x']));

    expect((string) $result)->toContain('Error:')
        ->and((string) $result)->toContain('not allowed');
});

it('lists directory contents with file and dir indicators', function () {
    $dir = storage_path('app/tools/list-dir');
    if (! is_dir($dir.'/child')) {
        mkdir($dir.'/child', 0777, true);
    }
    file_put_contents($dir.'/file.txt', 'a');

    $tool = app(FileListTool::class);
    $result = $tool->handle(new Request(['path' => $dir]));

    expect((string) $result)->toContain('child/')
        ->and((string) $result)->toContain('file.txt');
});

it('blocks dangerous shell commands from configured blocklist', function (string $command) {
    $tool = app(ShellTool::class);
    $result = $tool->handle(new Request(['command' => $command]));

    expect((string) $result)->toContain('Error:')
        ->and((string) $result)->toContain('blocked');
})->with([
    'rm -rf /',
    'mkfs.ext4 /dev/sda',
    'dd if=/dev/zero of=/dev/sda',
    ':(){ :|:& };:',
    'chmod -R 777 /',
]);

it('executes safe shell commands and returns stdout stderr and exit code', function () {
    $command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('fwrite(STDOUT, "ok"); fwrite(STDERR, "warn"); exit(3);');

    $tool = app(ShellTool::class);
    $result = (string) $tool->handle(new Request(['command' => $command]));

    expect($result)->toContain('Exit code: 3')
        ->and($result)->toContain('stdout:')
        ->and($result)->toContain('ok')
        ->and($result)->toContain('stderr:')
        ->and($result)->toContain('warn');
});

it('times out shell commands when they exceed timeout', function () {
    $command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('sleep(2);');

    $tool = app(ShellTool::class);
    $result = (string) $tool->handle(new Request([
        'command' => $command,
        'timeout' => 1,
    ]));

    expect(strtolower($result))->toContain('timed out');
});

it('returns search results for web search tool', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::response(
            '<html><body>'.
            '<div class="result"><a class="result__a" href="https://laravel.com">Laravel</a>'.
            '<a class="result__snippet">The PHP Framework For Web Artisans.</a></div>'.
            '</body></html>',
        ),
    ]);

    $tool = app(WebSearchTool::class);
    $result = (string) $tool->handle(new Request(['query' => 'laravel process facade']));

    expect($result)->toContain('laravel process facade')
        ->and($result)->toContain('laravel.com');
});
