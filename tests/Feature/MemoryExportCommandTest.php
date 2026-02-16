<?php

use App\Enums\MemoryType;
use App\Memory\MemoryService;
use App\Models\Procedure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('exports memories as markdown to file', function () {
    $service = app(MemoryService::class);
    $service->store(MemoryType::Fact, 'user.name', 'Vijay');
    $service->store(MemoryType::Preference, 'theme', 'dark mode');

    $path = sys_get_temp_dir().'/aegis-export-md-'.uniqid().'.md';

    $this->artisan("aegis:memory:export markdown --output={$path}")
        ->assertExitCode(0);

    $content = file_get_contents($path);

    expect($content)->toContain('Aegis Memory Export')
        ->and($content)->toContain('user.name')
        ->and($content)->toContain('Vijay')
        ->and($content)->toContain('theme');

    @unlink($path);
});

it('exports memories as json to file', function () {
    $service = app(MemoryService::class);
    $service->store(MemoryType::Fact, 'user.name', 'Vijay');

    $path = sys_get_temp_dir().'/aegis-export-json-'.uniqid().'.json';

    $this->artisan("aegis:memory:export json --output={$path}")
        ->assertExitCode(0);

    $content = file_get_contents($path);

    expect($content)->toContain('"key": "user.name"')
        ->and($content)->toContain('"value": "Vijay"');

    @unlink($path);
});

it('includes procedures in markdown export', function () {
    Procedure::query()->create([
        'trigger' => 'user writes code',
        'instruction' => 'use const instead of var',
        'is_active' => true,
    ]);

    $path = sys_get_temp_dir().'/aegis-export-proc-'.uniqid().'.md';

    $this->artisan("aegis:memory:export markdown --output={$path}")
        ->assertExitCode(0);

    $content = file_get_contents($path);

    expect($content)->toContain('Procedures')
        ->and($content)->toContain('use const instead of var');

    @unlink($path);
});

it('rejects invalid format', function () {
    $this->artisan('aegis:memory:export xml')
        ->expectsOutputToContain('Invalid format')
        ->assertExitCode(1);
});

it('works with empty database', function () {
    $path = sys_get_temp_dir().'/aegis-export-empty-'.uniqid().'.md';

    $this->artisan("aegis:memory:export markdown --output={$path}")
        ->assertExitCode(0);

    $content = file_get_contents($path);

    expect($content)->toContain('Aegis Memory Export');

    @unlink($path);
});

it('outputs to stdout when no output option', function () {
    $service = app(MemoryService::class);
    $service->store(MemoryType::Fact, 'user.name', 'Vijay');

    Artisan::call('aegis:memory:export', ['format' => 'markdown']);
    $output = Artisan::output();

    expect($output)->toContain('Aegis Memory Export')
        ->and($output)->toContain('user.name');
});
