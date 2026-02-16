<?php

use App\Tools\CodeExecutionTool;
use Laravel\Ai\Tools\Request;

it('executes PHP code and returns output', function () {
    $tool = new CodeExecutionTool;
    $result = $tool->handle(new Request([
        'language' => 'php',
        'code' => '<?php echo "Hello World";',
    ]));

    expect($result)->toContain('Hello World')
        ->and($result)->toContain('Exit code: 0');
});

it('captures stderr from PHP code', function () {
    $tool = new CodeExecutionTool;
    $result = $tool->handle(new Request([
        'language' => 'php',
        'code' => '<?php trigger_error("test warning", E_USER_WARNING);',
    ]));

    expect($result)->toContain('test warning');
});

it('returns error for empty code', function () {
    $tool = new CodeExecutionTool;
    $result = $tool->handle(new Request(['language' => 'php', 'code' => '']));

    expect($result)->toContain('Error');
});

it('enforces timeout', function () {
    $tool = new CodeExecutionTool;
    $result = $tool->handle(new Request([
        'language' => 'php',
        'code' => '<?php while(true) { usleep(100000); }',
        'timeout' => 2,
    ]));

    expect(strtolower($result))->toContain('timed out');
});

it('blocks dangerous code patterns', function () {
    $tool = new CodeExecutionTool;

    $result = $tool->handle(new Request([
        'language' => 'php',
        'code' => '<?php exec("rm -rf /");',
    ]));

    expect($result)->toContain('blocked');
});

it('supports bash execution', function () {
    $tool = new CodeExecutionTool;
    $result = $tool->handle(new Request([
        'language' => 'bash',
        'code' => 'echo "hello from bash"',
    ]));

    expect($result)->toContain('hello from bash')
        ->and($result)->toContain('Exit code: 0');
});

it('requires execute permission', function () {
    $tool = new CodeExecutionTool;

    expect($tool->requiredPermission())->toBe('execute');
});

it('supports python execution', function () {
    $tool = new CodeExecutionTool;
    $result = $tool->handle(new Request([
        'language' => 'python',
        'code' => 'print("hello from python")',
    ]));

    expect($result)->toContain('hello from python')
        ->and($result)->toContain('Exit code: 0');
});

it('rejects unsupported language', function () {
    $tool = new CodeExecutionTool;
    $result = $tool->handle(new Request([
        'language' => 'ruby',
        'code' => 'puts "hello"',
    ]));

    expect($result)->toContain('Unsupported language');
});

it('caps timeout at 60 seconds', function () {
    $tool = new CodeExecutionTool;
    $result = $tool->handle(new Request([
        'language' => 'php',
        'code' => '<?php echo "fast";',
        'timeout' => 999,
    ]));

    expect($result)->toContain('fast')
        ->and($result)->toContain('Exit code: 0');
});

it('blocks shell_exec pattern', function () {
    $tool = new CodeExecutionTool;
    $result = $tool->handle(new Request([
        'language' => 'php',
        'code' => '<?php shell_exec("ls");',
    ]));

    expect($result)->toContain('blocked');
});

it('blocks proc_open pattern', function () {
    $tool = new CodeExecutionTool;
    $result = $tool->handle(new Request([
        'language' => 'php',
        'code' => '<?php proc_open("ls", [], $pipes);',
    ]));

    expect($result)->toContain('blocked');
});

it('has correct tool metadata', function () {
    $tool = new CodeExecutionTool;

    expect($tool->name())->toBe('code_execution')
        ->and((string) $tool->description())->toContain('Execute');
});
