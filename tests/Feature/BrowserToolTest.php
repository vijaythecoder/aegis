<?php

use App\Tools\BrowserSession;
use App\Tools\BrowserTool;
use Laravel\Ai\Tools\Request;

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
        ->with(null)
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
