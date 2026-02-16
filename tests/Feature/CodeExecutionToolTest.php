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

it('has correct tool metadata', function () {
    $tool = new CodeExecutionTool;

    expect($tool->name())->toBe('code_execution')
        ->and((string) $tool->description())->toContain('Execute');
});
