<?php

namespace App\Tools;

use App\Agent\ToolResult;

class BrowserTool extends BaseTool
{
    public function __construct(
        private readonly BrowserSession $session,
    ) {}

    public function name(): string
    {
        return 'browser';
    }

    public function description(): string
    {
        return 'Browse the web: navigate, screenshot, click, fill forms, extract text';
    }

    public function requiredPermission(): string
    {
        return 'browser';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['action'],
            'properties' => [
                'action' => ['type' => 'string', 'description' => 'Action: navigate, screenshot, click, fill, get_text, evaluate, get_page_content'],
                'url' => ['type' => 'string', 'description' => 'URL to navigate to'],
                'selector' => ['type' => 'string', 'description' => 'CSS selector for element'],
                'value' => ['type' => 'string', 'description' => 'Value for fill action'],
                'javascript' => ['type' => 'string', 'description' => 'JavaScript to evaluate'],
            ],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? '');

        try {
            return match ($action) {
                'navigate' => $this->navigate($input),
                'screenshot' => $this->screenshot($input),
                'click' => $this->click($input),
                'fill' => $this->fill($input),
                'get_text' => $this->getText($input),
                'evaluate' => $this->evaluate($input),
                'get_page_content' => $this->getPageContent(),
                default => new ToolResult(false, null, "Unknown browser action: {$action}"),
            };
        } catch (\Throwable $e) {
            return new ToolResult(false, null, $e->getMessage());
        }
    }

    private function navigate(array $input): ToolResult
    {
        $url = (string) ($input['url'] ?? '');
        if ($url === '') {
            return new ToolResult(false, null, 'URL is required for navigate action.');
        }

        if ($this->isBlockedUrl($url)) {
            return new ToolResult(false, null, 'URL is blocked by browser security policy.');
        }

        $result = $this->session->navigate($url);

        return new ToolResult(true, $result);
    }

    private function screenshot(array $input): ToolResult
    {
        $selector = isset($input['selector']) ? (string) $input['selector'] : null;
        $path = $this->session->screenshot($selector);

        return new ToolResult(true, ['path' => $path]);
    }

    private function click(array $input): ToolResult
    {
        $selector = (string) ($input['selector'] ?? '');
        if ($selector === '') {
            return new ToolResult(false, null, 'Selector is required for click action.');
        }

        $clicked = $this->session->click($selector);

        return new ToolResult(true, ['clicked' => $clicked]);
    }

    private function fill(array $input): ToolResult
    {
        $selector = (string) ($input['selector'] ?? '');
        $value = (string) ($input['value'] ?? '');
        if ($selector === '') {
            return new ToolResult(false, null, 'Selector is required for fill action.');
        }

        $filled = $this->session->fill($selector, $value);

        return new ToolResult(true, ['filled' => $filled]);
    }

    private function getText(array $input): ToolResult
    {
        $selector = (string) ($input['selector'] ?? '');
        if ($selector === '') {
            return new ToolResult(false, null, 'Selector is required for get_text action.');
        }

        $text = $this->session->getText($selector);

        return new ToolResult(true, ['text' => $text]);
    }

    private function evaluate(array $input): ToolResult
    {
        $javascript = (string) ($input['javascript'] ?? '');
        if ($javascript === '') {
            return new ToolResult(false, null, 'JavaScript is required for evaluate action.');
        }

        $value = $this->session->evaluate($javascript);

        return new ToolResult(true, ['value' => $value]);
    }

    private function getPageContent(): ToolResult
    {
        $content = $this->session->getPageContent();

        return new ToolResult(true, ['content' => $content]);
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
