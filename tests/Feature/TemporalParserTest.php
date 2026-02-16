<?php

use App\Memory\TemporalParser;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-02-15 12:00:00');
    $this->parser = new TemporalParser;
});

afterEach(function () {
    Carbon::setTestNow();
});

it('parses "yesterday"', function () {
    $result = $this->parser->parse('What did we discuss yesterday?');

    expect($result)->not->toBeNull()
        ->and($result['from']->toDateString())->toBe('2026-02-14')
        ->and($result['to']->toDateString())->toBe('2026-02-14');
});

it('parses "last week"', function () {
    $result = $this->parser->parse('What did we talk about last week?');

    expect($result)->not->toBeNull()
        ->and($result['from']->isMonday())->toBeTrue()
        ->and($result['to']->isSunday())->toBeTrue();
});

it('parses "3 days ago"', function () {
    $result = $this->parser->parse('What did I say 3 days ago?');

    expect($result)->not->toBeNull()
        ->and($result['from']->toDateString())->toBe('2026-02-12')
        ->and($result['to']->toDateString())->toBe('2026-02-12');
});

it('parses "last month"', function () {
    $result = $this->parser->parse('What happened last month?');

    expect($result)->not->toBeNull()
        ->and($result['from']->toDateString())->toBe('2026-01-01')
        ->and($result['to']->toDateString())->toBe('2026-01-31');
});

it('parses "2 weeks ago"', function () {
    $result = $this->parser->parse('We discussed something 2 weeks ago');

    expect($result)->not->toBeNull()
        ->and($result['from']->isMonday())->toBeTrue();
});

it('parses "recently"', function () {
    $result = $this->parser->parse('What did we discuss recently?');

    expect($result)->not->toBeNull()
        ->and($result['from']->toDateString())->toBe('2026-02-08');
});

it('parses "today"', function () {
    $result = $this->parser->parse('What did we discuss earlier today?');

    expect($result)->not->toBeNull()
        ->and($result['from']->toDateString())->toBe('2026-02-15');
});

it('returns null for non-temporal queries', function () {
    $result = $this->parser->parse('What is the capital of France?');

    expect($result)->toBeNull();
});
