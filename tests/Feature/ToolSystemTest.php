<?php

use App\Tools\FileListTool;
use App\Tools\FileReadTool;
use App\Tools\FileWriteTool;
use App\Tools\ShellTool;
use App\Tools\ToolRegistry;
use App\Tools\WebSearchTool;

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
    $result = $tool->execute(['path' => $path]);

    expect($result->success)->toBeTrue()
        ->and($result->output)->toBe('hello-aegis')
        ->and($result->error)->toBeNull();
});

it('rejects reading /etc/passwd', function () {
    $tool = app(FileReadTool::class);
    $result = $tool->execute(['path' => '/etc/passwd']);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('not allowed');
});

it('rejects reading /etc/shadow', function () {
    $tool = app(FileReadTool::class);
    $result = $tool->execute(['path' => '/etc/shadow']);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('not allowed');
});

it('writes file contents in allowed paths and creates directories', function () {
    $path = storage_path('app/tools/nested/write-ok.txt');

    $tool = app(FileWriteTool::class);
    $result = $tool->execute(['path' => $path, 'content' => 'written-content']);

    expect($result->success)->toBeTrue()
        ->and(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toBe('written-content');
});

it('rejects writing outside allowed paths', function () {
    $tool = app(FileWriteTool::class);
    $result = $tool->execute(['path' => '/tmp/aegis-disallowed-write.txt', 'content' => 'x']);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('not allowed');
});

it('lists directory contents with file and dir indicators', function () {
    $dir = storage_path('app/tools/list-dir');
    if (! is_dir($dir.'/child')) {
        mkdir($dir.'/child', 0777, true);
    }
    file_put_contents($dir.'/file.txt', 'a');

    $tool = app(FileListTool::class);
    $result = $tool->execute(['path' => $dir]);

    expect($result->success)->toBeTrue()
        ->and($result->output)->toContain('child/', 'file.txt');
});

it('blocks dangerous shell commands from configured blocklist', function (string $command) {
    $tool = app(ShellTool::class);
    $result = $tool->execute(['command' => $command]);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('blocked');
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
    $result = $tool->execute(['command' => $command]);

    expect($result->success)->toBeTrue()
        ->and($result->output)->toBeArray()
        ->and($result->output['stdout'])->toContain('ok')
        ->and($result->output['stderr'])->toContain('warn')
        ->and($result->output['exit_code'])->toBe(3);
});

it('times out shell commands when they exceed timeout', function () {
    $command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('sleep(2);');

    $tool = app(ShellTool::class);
    $result = $tool->execute([
        'command' => $command,
        'timeout' => 1,
    ]);

    expect($result->success)->toBeFalse()
        ->and(strtolower((string) $result->error))->toContain('timed out');
});

it('returns stubbed response for web search tool', function () {
    $tool = app(WebSearchTool::class);
    $result = $tool->execute(['query' => 'laravel process facade']);

    expect($result->success)->toBeTrue()
        ->and($result->output)->toContain('Web search not yet implemented')
        ->and($result->output)->toContain('laravel process facade');
});
