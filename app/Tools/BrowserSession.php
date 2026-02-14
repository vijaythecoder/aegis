<?php

namespace App\Tools;

use Symfony\Component\Process\Process;

class BrowserSession
{
    private static array $instances = [];

    private ?Process $process = null;

    private array $tabs = [];

    private int $nextTabId = 1;

    private bool $launched = false;

    public function __construct(
        private readonly int $maxTabs = 5,
        private readonly int $timeout = 30,
        private readonly string $screenshotPath = '',
        private readonly bool $headless = true,
    ) {}

    public static function forSession(?string $sessionId = null): self
    {
        $key = $sessionId ?? 'default';
        if (! isset(self::$instances[$key])) {
            self::$instances[$key] = new self(
                (int) config('aegis.browser.max_tabs', 5),
                (int) config('aegis.browser.timeout', 30),
                (string) config('aegis.browser.screenshot_path', storage_path('app/screenshots')),
                (bool) config('aegis.browser.headless', true),
            );
        }

        return self::$instances[$key];
    }

    public static function forgetSession(?string $sessionId = null): void
    {
        $key = $sessionId ?? 'default';
        if (! isset(self::$instances[$key])) {
            return;
        }

        self::$instances[$key]->cleanup();
        unset(self::$instances[$key]);
    }

    public function launch(): void
    {
        if ($this->launched) {
            return;
        }

        $this->startBridgeProcess();
    }

    public function navigate(string $url): array
    {
        return $this->runBridgeCommand([
            'action' => 'navigate',
            'url' => $url,
        ]);
    }

    public function screenshot(?string $selector = null): string
    {
        $result = $this->runBridgeCommand([
            'action' => 'screenshot',
            'selector' => $selector,
        ]);

        return (string) ($result['path'] ?? '');
    }

    public function click(string $selector): bool
    {
        $result = $this->runBridgeCommand([
            'action' => 'click',
            'selector' => $selector,
        ]);

        return (bool) ($result['clicked'] ?? false);
    }

    public function fill(string $selector, string $value): bool
    {
        $result = $this->runBridgeCommand([
            'action' => 'fill',
            'selector' => $selector,
            'value' => $value,
        ]);

        return (bool) ($result['filled'] ?? false);
    }

    public function getText(string $selector): string
    {
        $result = $this->runBridgeCommand([
            'action' => 'get_text',
            'selector' => $selector,
        ]);

        return (string) ($result['text'] ?? '');
    }

    public function evaluate(string $javascript): mixed
    {
        $result = $this->runBridgeCommand([
            'action' => 'evaluate',
            'javascript' => $javascript,
        ]);

        return $result['value'] ?? null;
    }

    public function getPageContent(): string
    {
        $result = $this->runBridgeCommand([
            'action' => 'get_page_content',
        ]);

        return (string) ($result['content'] ?? '');
    }

    public function openTab(): int
    {
        $this->launch();

        if (count($this->tabs) >= $this->maxTabs) {
            $oldestId = (int) array_key_first($this->tabs);
            $this->closeTab($oldestId);
        }

        $tabId = $this->nextTabId++;
        $this->tabs[$tabId] = [
            'id' => $tabId,
            'opened_at' => microtime(true),
        ];

        return $tabId;
    }

    public function closeTab(int $index): void
    {
        unset($this->tabs[$index]);
    }

    public function tabCount(): int
    {
        return count($this->tabs);
    }

    public function cleanup(): void
    {
        $this->tabs = [];
        $this->nextTabId = 1;
        $this->stopBridgeProcess();
    }

    public function isLaunched(): bool
    {
        return $this->launched;
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    protected function startBridgeProcess(): void
    {
        $this->process = new Process(['node', '-e', 'process.stdin.resume();']);
        $this->process->setTimeout(null);
        $this->process->start();

        usleep(100000);

        if (! $this->process->isRunning()) {
            $error = trim($this->process->getErrorOutput());
            if ($error === '') {
                $error = 'Failed to launch Node.js browser bridge process.';
            }
            throw new \RuntimeException($error);
        }

        $this->launched = true;
    }

    protected function stopBridgeProcess(): void
    {
        if ($this->process instanceof Process && $this->process->isRunning()) {
            $this->process->stop(2);
        }

        $this->process = null;
        $this->launched = false;
    }

    protected function runBridgeCommand(array $payload): array
    {
        $this->launch();

        if ($this->tabCount() === 0) {
            $this->openTab();
        }

        $activeTab = (int) array_key_last($this->tabs);
        $payload['tab_id'] = $activeTab;
        $payload['headless'] = $this->headless;
        $payload['screenshot_path'] = $this->resolveScreenshotPath();
        $payload['timeout'] = $this->timeout;

        $response = $this->executeNodeCommand($payload);
        if (($response['ok'] ?? false) !== true) {
            $error = (string) ($response['error'] ?? 'Browser bridge command failed.');
            throw new \RuntimeException($error);
        }

        return (array) ($response['output'] ?? []);
    }

    protected function setLaunchedState(bool $launched): void
    {
        $this->launched = $launched;
        if (! $launched) {
            $this->process = null;
        }
    }

    private function resolveScreenshotPath(): string
    {
        $path = $this->screenshotPath !== ''
            ? $this->screenshotPath
            : (string) config('aegis.browser.screenshot_path', storage_path('app/screenshots'));

        if (! is_dir($path)) {
            @mkdir($path, 0777, true);
        }

        return $path;
    }

    private function executeNodeCommand(array $payload): array
    {
        $encodedPayload = base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR));
        $script = <<<'JS'
const fs = require('fs');

(async () => {
  const payload = JSON.parse(Buffer.from(process.argv[1], 'base64').toString('utf8'));
  const playwright = require('playwright');
  const browser = await playwright.chromium.launch({ headless: payload.headless !== false });
  const context = await browser.newContext();
  const page = await context.newPage();

  let output = {};
  if (payload.action === 'navigate') {
    await page.goto(payload.url, { waitUntil: 'domcontentloaded', timeout: (payload.timeout || 30) * 1000 });
    output = { title: await page.title(), url: page.url() };
  } else if (payload.action === 'screenshot') {
    const file = `${payload.screenshot_path}/shot-${Date.now()}.png`;
    if (payload.selector) {
      await page.locator(payload.selector).screenshot({ path: file });
    } else {
      await page.screenshot({ path: file, fullPage: true });
    }
    output = { path: file };
  } else if (payload.action === 'click') {
    await page.click(payload.selector);
    output = { clicked: true };
  } else if (payload.action === 'fill') {
    await page.fill(payload.selector, payload.value || '');
    output = { filled: true };
  } else if (payload.action === 'get_text') {
    const text = await page.textContent(payload.selector);
    output = { text: text || '' };
  } else if (payload.action === 'evaluate') {
    const value = await page.evaluate(payload.javascript || 'null');
    output = { value };
  } else if (payload.action === 'get_page_content') {
    output = { content: await page.content() };
  } else {
    throw new Error(`Unknown browser action: ${payload.action}`);
  }

  await browser.close();
  process.stdout.write(JSON.stringify({ ok: true, output }));
})().catch((error) => {
  process.stdout.write(JSON.stringify({ ok: false, error: error.message || 'Browser bridge failure' }));
  process.exitCode = 1;
});
JS;

        $process = new Process(['node', '-e', $script, $encodedPayload]);
        $process->setTimeout($this->timeout);
        $process->run();

        $stdout = trim($process->getOutput());
        if ($stdout === '') {
            return [
                'ok' => false,
                'error' => trim($process->getErrorOutput()) ?: 'Empty response from browser bridge.',
            ];
        }

        $decoded = json_decode($stdout, true);
        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'Invalid JSON response from browser bridge.',
            ];
        }

        return $decoded;
    }
}
