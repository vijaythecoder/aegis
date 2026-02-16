<?php

use App\Tools\BrowserSession;
use App\Tools\BrowserTool;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    BrowserSession::fakePlaywrightAvailable(true);
});

afterEach(function () {
    BrowserSession::resetPlaywrightCache();
});

it('navigates to url and returns page metadata', function () {
    $session = Mockery::mock(BrowserSession::class);
    $session->shouldReceive('navigate')
        ->once()
        ->with('https://example.com')
        ->andReturn([
            'title' => 'Example Domain',
            'url' => 'https://example.com',
        ]);

    app()->instance(BrowserSession::class, $session);

    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'navigate',
        'url' => 'https://example.com',
    ]));

    expect($result)->toContain('Example Domain')
        ->and($result)->toContain('example.com');
});

it('captures screenshot and returns file path', function () {
    $session = Mockery::mock(BrowserSession::class);
    $session->shouldReceive('screenshot')
        ->once()
        ->with(null, null)
        ->andReturn(storage_path('app/screenshots/example.png'));

    app()->instance(BrowserSession::class, $session);

    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'screenshot',
    ]));

    expect($result)->toContain('screenshots')
        ->and($result)->toContain('.png');
});

it('blocks file scheme urls', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'navigate',
        'url' => 'file:///etc/passwd',
    ]));

    expect(strtolower($result))->toContain('blocked');
});

it('blocks chrome scheme urls', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'navigate',
        'url' => 'chrome://settings',
    ]));

    expect(strtolower($result))->toContain('blocked');
});

it('respects browser tab limit by closing oldest tab', function () {
    $session = new class(5) extends BrowserSession
    {
        protected function startBridgeProcess(): void
        {
            $this->setLaunchedState(true);
        }

        protected function runBridgeCommand(array $payload): array
        {
            return [];
        }
    };

    for ($i = 0; $i < 5; $i++) {
        $session->openTab();
    }

    $session->openTab();

    expect($session->tabCount())->toBe(5);
});

it('cleans up browser session state', function () {
    $session = new class(5) extends BrowserSession
    {
        public bool $bridgeStopped = false;

        protected function startBridgeProcess(): void
        {
            $this->setLaunchedState(true);
        }

        protected function stopBridgeProcess(): void
        {
            $this->bridgeStopped = true;
            $this->setLaunchedState(false);
        }

        protected function runBridgeCommand(array $payload): array
        {
            return [];
        }
    };

    $session->openTab();
    $session->openTab();
    $session->cleanup();

    expect($session->tabCount())->toBe(0)
        ->and($session->isLaunched())->toBeFalse()
        ->and($session->bridgeStopped)->toBeTrue();
});

it('gets text content from selector', function () {
    $session = Mockery::mock(BrowserSession::class);
    $session->shouldReceive('getText')
        ->once()
        ->with('h1')
        ->andReturn('Example Domain');

    app()->instance(BrowserSession::class, $session);

    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'get_text',
        'selector' => 'h1',
    ]));

    expect($result)->toBe('Example Domain');
});

it('blocks javascript scheme urls', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'navigate',
        'url' => 'javascript:alert(1)',
    ]));

    expect(strtolower($result))->toContain('blocked');
});

it('blocks data scheme urls', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'navigate',
        'url' => 'data:text/html,<h1>test</h1>',
    ]));

    expect(strtolower($result))->toContain('blocked');
});

it('blocks localhost urls by default', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'navigate',
        'url' => 'http://localhost:3000',
    ]));

    expect(strtolower($result))->toContain('blocked');
});

it('blocks 127.0.0.1 urls by default', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'navigate',
        'url' => 'http://127.0.0.1:8080',
    ]));

    expect(strtolower($result))->toContain('blocked');
});

it('returns error when playwright is not available', function () {
    BrowserSession::fakePlaywrightAvailable(false);

    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'navigate',
        'url' => 'https://example.com',
    ]));

    expect($result)->toContain('unavailable')
        ->and($result)->toContain('playwright');
});

it('returns error for unknown action', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'nonexistent',
    ]));

    expect($result)->toContain('Unknown browser action');
});

it('returns error when navigate url is missing', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'navigate',
    ]));

    expect($result)->toContain('URL is required');
});

it('returns error when click selector is missing', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'click',
    ]));

    expect($result)->toContain('Selector is required');
});

it('returns error when fill selector is missing', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'fill',
    ]));

    expect($result)->toContain('Selector is required');
});

it('returns error when get_text selector is missing', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'get_text',
    ]));

    expect($result)->toContain('Selector is required');
});

it('returns error when evaluate javascript is missing', function () {
    $result = (string) app(BrowserTool::class)->handle(new Request([
        'action' => 'evaluate',
    ]));

    expect($result)->toContain('JavaScript is required');
});
