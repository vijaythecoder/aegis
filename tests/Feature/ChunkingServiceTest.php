<?php

use App\Rag\ChunkingService;

beforeEach(function () {
    $this->service = app(ChunkingService::class);
    $this->tempDir = storage_path('framework/testing/chunking');
    if (! is_dir($this->tempDir)) {
        mkdir($this->tempDir, 0755, true);
    }
});

afterEach(function () {
    $files = glob($this->tempDir.'/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

test('chunks PHP code preserving function boundaries', function () {
    $code = <<<'PHP'
<?php

namespace App\Services;

class UserService
{
    public function createUser(string $name, string $email): User
    {
        $user = new User();
        $user->name = $name;
        $user->email = $email;
        $user->save();

        return $user;
    }

    public function deleteUser(int $id): bool
    {
        $user = User::findOrFail($id);
        $user->delete();

        return true;
    }

    public function updateUser(int $id, array $data): User
    {
        $user = User::findOrFail($id);
        $user->fill($data);
        $user->save();

        return $user;
    }
}
PHP;

    $path = $this->tempDir.'/UserService.php';
    file_put_contents($path, $code);

    $chunks = $this->service->chunk($path);

    expect($chunks)->toBeArray()->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect($chunk)->toHaveKeys(['content', 'metadata']);
        expect($chunk['metadata'])->toHaveKeys(['file_path', 'start_line', 'end_line', 'chunk_index', 'file_type']);
        expect($chunk['metadata']['file_type'])->toBe('php');
        expect($chunk['metadata']['file_path'])->toBe($path);
        expect($chunk['content'])->toBeString()->not->toBeEmpty();
    }

    $allContent = implode("\n", array_column($chunks, 'content'));
    expect($allContent)->toContain('createUser');
    expect($allContent)->toContain('deleteUser');
    expect($allContent)->toContain('updateUser');
});

test('chunks markdown by headings', function () {
    $markdown = <<<'MD'
# Introduction

This is the introduction section with some content about the project.
It spans multiple lines and has important details.

## Getting Started

To get started, install the dependencies:

```bash
composer install
```

Then run the migrations.

## Configuration

Configure the application by editing the `.env` file.
Set the database credentials and API keys.

### Database

The database section requires special attention.
Make sure to set the correct driver.

## Usage

Use the application by running `php artisan serve`.
MD;

    $path = $this->tempDir.'/README.md';
    file_put_contents($path, $markdown);

    $chunks = $this->service->chunk($path);

    expect($chunks)->toBeArray()->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect($chunk['metadata']['file_type'])->toBe('markdown');
    }

    $contents = array_column($chunks, 'content');
    $hasIntro = false;
    $hasGettingStarted = false;
    foreach ($contents as $content) {
        if (str_contains($content, 'Introduction') || str_contains($content, 'introduction section')) {
            $hasIntro = true;
        }
        if (str_contains($content, 'Getting Started') || str_contains($content, 'install the dependencies')) {
            $hasGettingStarted = true;
        }
    }
    expect($hasIntro)->toBeTrue();
    expect($hasGettingStarted)->toBeTrue();
});

test('chunks plain text with sentence-based splitting and overlap', function () {
    $sentences = [];
    for ($i = 1; $i <= 50; $i++) {
        $sentences[] = "This is sentence number {$i} with enough words to make it meaningful for chunking purposes.";
    }
    $text = implode(' ', $sentences);

    $path = $this->tempDir.'/document.txt';
    file_put_contents($path, $text);

    $chunks = $this->service->chunk($path);

    expect($chunks)->toBeArray();
    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        expect($chunk['metadata']['file_type'])->toBe('text');
        expect($chunk['metadata']['chunk_index'])->toBeInt();
    }

    if (count($chunks) >= 2) {
        $firstChunkEnd = $chunks[0]['content'];
        $secondChunkStart = $chunks[1]['content'];

        $firstWords = explode(' ', trim($firstChunkEnd));
        $secondWords = explode(' ', trim($secondChunkStart));
        $lastWordsOfFirst = array_slice($firstWords, -10);
        $firstWordsOfSecond = array_slice($secondWords, 0, 10);

        $overlap = array_intersect($lastWordsOfFirst, $firstWordsOfSecond);
        expect(count($overlap))->toBeGreaterThan(0, 'Consecutive chunks should have overlapping content');
    }
});

test('rejects files exceeding max file size', function () {
    config(['aegis.rag.max_file_size_mb' => 0.001]);

    $path = $this->tempDir.'/large_file.txt';
    file_put_contents($path, str_repeat('x', 2000));

    $chunks = $this->service->chunk($path);

    expect($chunks)->toBeArray()->toBeEmpty();
});

test('returns empty array for non-existent file', function () {
    $chunks = $this->service->chunk('/nonexistent/file.txt');

    expect($chunks)->toBeArray()->toBeEmpty();
});

test('chunks JavaScript files preserving function boundaries', function () {
    $code = <<<'JS'
import { useState } from 'react';

export function useCounter(initial = 0) {
    const [count, setCount] = useState(initial);

    const increment = () => setCount(c => c + 1);
    const decrement = () => setCount(c => c - 1);

    return { count, increment, decrement };
}

export function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}

export class Calculator {
    constructor() {
        this.result = 0;
    }

    add(n) {
        this.result += n;
        return this;
    }

    subtract(n) {
        this.result -= n;
        return this;
    }
}
JS;

    $path = $this->tempDir.'/utils.js';
    file_put_contents($path, $code);

    $chunks = $this->service->chunk($path);

    expect($chunks)->toBeArray()->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect($chunk['metadata']['file_type'])->toBe('code');
    }

    $allContent = implode("\n", array_column($chunks, 'content'));
    expect($allContent)->toContain('useCounter');
    expect($allContent)->toContain('Calculator');
});

test('chunk metadata includes correct line numbers', function () {
    $code = <<<'PHP'
<?php

function first(): void
{
    echo 'first';
}

function second(): void
{
    echo 'second';
}
PHP;

    $path = $this->tempDir.'/functions.php';
    file_put_contents($path, $code);

    $chunks = $this->service->chunk($path);

    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $i => $chunk) {
        expect($chunk['metadata']['start_line'])->toBeInt()->toBeGreaterThanOrEqual(1);
        expect($chunk['metadata']['end_line'])->toBeInt()->toBeGreaterThanOrEqual($chunk['metadata']['start_line']);
        expect($chunk['metadata']['chunk_index'])->toBe($i);
    }
});

test('respects configurable chunk size', function () {
    config(['aegis.rag.chunk_size' => 100]);

    $sentences = [];
    for ($i = 1; $i <= 100; $i++) {
        $sentences[] = "Sentence {$i} with some padding words to fill up space.";
    }
    $text = implode(' ', $sentences);

    $path = $this->tempDir.'/long_document.txt';
    file_put_contents($path, $text);

    $chunks = $this->service->chunk($path);

    expect(count($chunks))->toBeGreaterThan(5);
});

test('handles Python files as code type', function () {
    $code = <<<'PYTHON'
class UserService:
    def __init__(self, db):
        self.db = db

    def create_user(self, name, email):
        user = {"name": name, "email": email}
        self.db.insert(user)
        return user

    def delete_user(self, user_id):
        self.db.delete(user_id)

def helper_function():
    return "helper"
PYTHON;

    $path = $this->tempDir.'/service.py';
    file_put_contents($path, $code);

    $chunks = $this->service->chunk($path);

    expect($chunks)->toBeArray()->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect($chunk['metadata']['file_type'])->toBe('code');
    }

    $allContent = implode("\n", array_column($chunks, 'content'));
    expect($allContent)->toContain('create_user');
    expect($allContent)->toContain('delete_user');
});
