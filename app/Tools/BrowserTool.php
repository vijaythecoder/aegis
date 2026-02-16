<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class BrowserTool implements Tool
{
    public function __construct(
        private readonly BrowserSession $session,
    ) {}

    public function name(): string
    {
        return 'browser';
    }

    public function requiredPermission(): string
    {
        return 'execute';
    }

    public function description(): Stringable|string
    {
        return 'Browse the web: navigate, screenshot, click, fill forms, extract text';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('Action: navigate, screenshot, click, fill, get_text, evaluate, get_page_content')->required(),
            'url' => $schema->string()->description('URL to navigate to'),
            'selector' => $schema->string()->description('CSS selector for element'),
            'value' => $schema->string()->description('Value for fill action'),
            'javascript' => $schema->string()->description('JavaScript to evaluate'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        if (! BrowserSession::isPlaywrightAvailable()) {
            return 'Error: Browser tool is unavailable. The "playwright" npm package is not installed. Install it with: npm install playwright';
        }

        $action = (string) $request->string('action');

        try {
            return match ($action) {
                'navigate' => $this->navigate($request),
                'screenshot' => $this->screenshot($request),
                'click' => $this->click($request),
                'fill' => $this->fill($request),
                'get_text' => $this->getText($request),
                'evaluate' => $this->evaluate($request),
                'get_page_content' => $this->getPageContent(),
                default => "Error: Unknown browser action: {$action}",
            };
        } catch (\Throwable $e) {
            return 'Error: '.$e->getMessage();
        }
    }

    private function navigate(Request $request): string
    {
        $url = (string) $request->string('url');
        if ($url === '') {
            return 'Error: URL is required for navigate action.';
        }

        if ($this->isBlockedUrl($url)) {
            return 'Error: URL is blocked by browser security policy.';
        }

        $result = $this->session->navigate($url);

        return is_string($result) ? $result : json_encode($result);
    }

    private function screenshot(Request $request): string
    {
        $selector = $request['selector'] ?? null;
        $path = $this->session->screenshot($selector);

        return "Screenshot saved to: {$path}";
    }

    private function click(Request $request): string
    {
        $selector = (string) $request->string('selector');
        if ($selector === '') {
            return 'Error: Selector is required for click action.';
        }

        $this->session->click($selector);

        return "Clicked element: {$selector}";
    }

    private function fill(Request $request): string
    {
        $selector = (string) $request->string('selector');
        $value = (string) $request->string('value');
        if ($selector === '') {
            return 'Error: Selector is required for fill action.';
        }

        $this->session->fill($selector, $value);

        return "Filled element {$selector} with value.";
    }

    private function getText(Request $request): string
    {
        $selector = (string) $request->string('selector');
        if ($selector === '') {
            return 'Error: Selector is required for get_text action.';
        }

        $text = $this->session->getText($selector);

        return (string) $text;
    }

    private function evaluate(Request $request): string
    {
        $javascript = (string) $request->string('javascript');
        if ($javascript === '') {
            return 'Error: JavaScript is required for evaluate action.';
        }

        $value = $this->session->evaluate($javascript);

        return is_string($value) ? $value : json_encode($value);
    }

    private function getPageContent(): string
    {
        $content = $this->session->getPageContent();

        return (string) $content;
    }

    private function isBlockedUrl(string $url): bool
    {
        $normalized = strtolower(trim($url));

        $blockedSchemes = (array) config('aegis.browser.blocked_schemes', ['file://', 'chrome://', 'about:', 'javascript:', 'data:']);
        foreach ($blockedSchemes as $scheme) {
            if (str_starts_with($normalized, strtolower((string) $scheme))) {
                return true;
            }
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return true;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return true;
        }

        $allowLocalhost = (bool) config('aegis.browser.allow_localhost', false);
        if ($allowLocalhost) {
            return false;
        }

        $blockedHosts = array_map('strtolower', (array) config('aegis.browser.blocked_hosts', ['localhost', '127.0.0.1', '0.0.0.0']));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return in_array($host, $blockedHosts, true);
    }
}
