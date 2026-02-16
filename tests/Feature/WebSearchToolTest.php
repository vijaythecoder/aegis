<?php

use App\Tools\WebSearchTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

it('returns search results from DuckDuckGo HTML response', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::response(
            '<html><body>'.
            '<div class="result"><a class="result__a" href="https://php.net">PHP: Hypertext Preprocessor</a>'.
            '<a class="result__snippet">PHP is a popular general-purpose scripting language.</a></div>'.
            '<div class="result"><a class="result__a" href="https://www.php.net/docs.php">PHP: Documentation</a>'.
            '<a class="result__snippet">PHP documentation and manual.</a></div>'.
            '</body></html>',
        ),
    ]);

    $tool = new WebSearchTool;
    $result = $tool->handle(new Request(['query' => 'PHP programming']));

    expect($result)->toContain('php.net')
        ->and($result)->toContain('PHP');
});

it('handles empty search query gracefully', function () {
    $tool = new WebSearchTool;
    $result = $tool->handle(new Request(['query' => '']));

    expect($result)->toContain('Error');
});

it('handles HTTP failure gracefully', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::response('Server Error', 500),
    ]);

    $tool = new WebSearchTool;
    $result = $tool->handle(new Request(['query' => 'test search']));

    expect($result)->toContain('No results');
});

it('handles connection timeout gracefully', function () {
    Http::fake([
        'html.duckduckgo.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Timeout'),
    ]);

    $tool = new WebSearchTool;
    $result = $tool->handle(new Request(['query' => 'test timeout']));

    expect($result)->toContain('Error');
});

it('limits results to 5', function () {
    $results = '';
    for ($i = 0; $i < 10; $i++) {
        $results .= '<div class="result"><a class="result__a" href="https://example.com/'.$i.'">Result '.$i.'</a>'.
            '<a class="result__snippet">Snippet '.$i.'</a></div>';
    }

    Http::fake([
        'html.duckduckgo.com/*' => Http::response('<html><body>'.$results.'</body></html>'),
    ]);

    $tool = new WebSearchTool;
    $result = $tool->handle(new Request(['query' => 'many results']));

    $urlCount = substr_count($result, 'example.com/');
    expect($urlCount)->toBeLessThanOrEqual(5);
});

it('has correct tool metadata', function () {
    $tool = new WebSearchTool;

    expect($tool->name())->toBe('web_search')
        ->and($tool->requiredPermission())->toBe('read')
        ->and((string) $tool->description())->toContain('Search');
});
