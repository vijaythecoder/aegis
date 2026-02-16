<?php

use App\Security\DockerSandbox;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('aegis.security.sandbox_mode', 'auto');
    config()->set('aegis.plugins.sandbox.timeout', 30);
    config()->set('aegis.plugins.sandbox.memory_limit_mb', 128);
});

test('isAvailable returns true when docker is installed', function () {
    Process::fake([
        'docker info *' => Process::result(output: 'Server Version: 24.0.0'),
    ]);

    $sandbox = new DockerSandbox;

    expect($sandbox->isAvailable())->toBeTrue();
});

test('isAvailable returns false when docker is not installed', function () {
    Process::fake([
        'docker info *' => Process::result(exitCode: 1, errorOutput: 'command not found'),
    ]);

    $sandbox = new DockerSandbox;

    expect($sandbox->isAvailable())->toBeFalse();
});

test('execute runs command in docker container', function () {
    Process::fake([
        'docker info *' => Process::result(output: 'Server Version: 24.0.0'),
        'docker run *' => Process::result(output: 'hello world'),
    ]);

    config()->set('aegis.security.sandbox_mode', 'docker');

    $sandbox = new DockerSandbox;

    $result = $sandbox->execute('echo "hello world"');

    expect($result->success)->toBeTrue()
        ->and($result->output)->toBe('hello world');

    Process::assertRan(function ($process) {
        $command = $process->command;

        return str_contains($command, 'docker run')
            && str_contains($command, '--rm')
            && str_contains($command, '--network=none')
            && str_contains($command, '--memory=');
    });
});

test('execute falls back to process when docker unavailable in auto mode', function () {
    Process::fake([
        'docker info *' => Process::result(exitCode: 1, errorOutput: 'not found'),
        'bash *' => Process::result(output: 'fallback output'),
    ]);

    config()->set('aegis.security.sandbox_mode', 'auto');

    $sandbox = new DockerSandbox;

    $result = $sandbox->execute('echo "test"');

    expect($result->success)->toBeTrue()
        ->and($result->output)->toBe('fallback output');
});

test('execute uses process mode when configured', function () {
    Process::fake([
        'bash *' => Process::result(output: 'process output'),
    ]);

    config()->set('aegis.security.sandbox_mode', 'process');

    $sandbox = new DockerSandbox;

    $result = $sandbox->execute('echo "test"');

    expect($result->success)->toBeTrue()
        ->and($result->output)->toBe('process output');

    Process::assertDidntRun('docker info *');
});

test('execute returns error on timeout', function () {
    Process::fake([
        'bash *' => Process::result(exitCode: 124, errorOutput: 'timed out'),
    ]);

    config()->set('aegis.security.sandbox_mode', 'process');

    $sandbox = new DockerSandbox;

    $result = $sandbox->execute('sleep 999', timeout: 1);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('timed out');
});

test('execute blocks dangerous commands', function () {
    $sandbox = new DockerSandbox;

    $result = $sandbox->execute('rm -rf /');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('blocked');
});

test('execute respects resource limits in docker mode', function () {
    Process::fake([
        'docker info *' => Process::result(output: 'Server Version: 24.0.0'),
        'docker run *' => Process::result(output: 'ok'),
    ]);

    config()->set('aegis.security.sandbox_mode', 'docker');
    config()->set('aegis.plugins.sandbox.memory_limit_mb', 64);

    $sandbox = new DockerSandbox;

    $sandbox->execute('echo ok');

    Process::assertRan(function ($process) {
        $command = $process->command;

        return str_contains($command, '--memory=64m')
            && str_contains($command, '--cpus=0.5');
    });
});

test('resolvedMode returns correct mode based on config and availability', function () {
    Process::fake([
        'docker info *' => Process::result(output: 'Server Version: 24.0.0'),
    ]);

    config()->set('aegis.security.sandbox_mode', 'auto');
    $sandbox = new DockerSandbox;

    expect($sandbox->resolvedMode())->toBe('docker');

    Process::fake([
        'docker info *' => Process::result(exitCode: 1),
    ]);

    $sandbox = new DockerSandbox;
    expect($sandbox->resolvedMode())->toBe('process');

    config()->set('aegis.security.sandbox_mode', 'none');
    $sandbox = new DockerSandbox;
    expect($sandbox->resolvedMode())->toBe('none');
});
