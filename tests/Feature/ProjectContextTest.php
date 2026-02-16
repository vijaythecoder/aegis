<?php

use App\Agent\ProjectContextLoader;

beforeEach(function () {
    $this->loader = app(ProjectContextLoader::class);
    $this->tempDir = storage_path('framework/testing/project');
    if (! is_dir($this->tempDir.'/.aegis')) {
        mkdir($this->tempDir.'/.aegis', 0755, true);
    }
});

afterEach(function () {
    $files = glob($this->tempDir.'/.aegis/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    $cursorRules = $this->tempDir.'/.cursorrules';
    if (file_exists($cursorRules)) {
        unlink($cursorRules);
    }
});

test('loads .aegis/context.md when it exists', function () {
    config(['aegis.agent.project_path' => $this->tempDir]);

    file_put_contents($this->tempDir.'/.aegis/context.md', 'This project uses Laravel 12.');

    $context = $this->loader->load();

    expect($context)->toContain('This project uses Laravel 12.');
});

test('loads .aegis/instructions.md when it exists', function () {
    config(['aegis.agent.project_path' => $this->tempDir]);

    file_put_contents($this->tempDir.'/.aegis/instructions.md', 'Always use strict types.');

    $context = $this->loader->load();

    expect($context)->toContain('Always use strict types.');
});

test('loads .cursorrules for compatibility', function () {
    config(['aegis.agent.project_path' => $this->tempDir]);

    file_put_contents($this->tempDir.'/.cursorrules', 'Use TDD for all features.');

    $context = $this->loader->load();

    expect($context)->toContain('Use TDD for all features.');
});

test('returns empty string when no context files exist', function () {
    config(['aegis.agent.project_path' => $this->tempDir]);

    $aegisFiles = glob($this->tempDir.'/.aegis/*');
    foreach ($aegisFiles as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }

    $context = $this->loader->load();

    expect($context)->toBe('');
});

test('returns empty string when project path not configured', function () {
    config(['aegis.agent.project_path' => null]);

    $context = $this->loader->load();

    expect($context)->toBe('');
});

test('rejects context files larger than 50KB', function () {
    config(['aegis.agent.project_path' => $this->tempDir]);

    file_put_contents($this->tempDir.'/.aegis/context.md', str_repeat('x', 60000));

    $context = $this->loader->load();

    expect($context)->toBe('');
});

test('caches loaded context', function () {
    config(['aegis.agent.project_path' => $this->tempDir]);

    file_put_contents($this->tempDir.'/.aegis/context.md', 'Cached content.');

    $first = $this->loader->load();
    file_put_contents($this->tempDir.'/.aegis/context.md', 'Updated content.');
    $second = $this->loader->load();

    expect($first)->toBe($second);
});

test('merges multiple context files', function () {
    config(['aegis.agent.project_path' => $this->tempDir]);

    file_put_contents($this->tempDir.'/.aegis/context.md', 'Project context here.');
    file_put_contents($this->tempDir.'/.aegis/instructions.md', 'Project instructions here.');

    $context = $this->loader->load();

    expect($context)->toContain('Project context here.');
    expect($context)->toContain('Project instructions here.');
});
