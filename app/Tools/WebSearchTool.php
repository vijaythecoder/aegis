<?php

namespace App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class WebSearchTool implements Tool
{
    public function name(): string
    {
        return 'web_search';
    }

    public function requiredPermission(): string
    {
        return 'read';
    }

    public function description(): Stringable|string
    {
        return 'Search the web for current information using DuckDuckGo.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The search query.')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request->string('query'));

        if ($query === '') {
            return 'Error: Search query is required.';
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'Aegis/1.0'])
                ->asForm()
                ->post('https://html.duckduckgo.com/html/', ['q' => $query]);

            if (! $response->successful()) {
                return 'No results found for: '.$query;
            }

            return $this->parseResults($response->body(), $query);
        } catch (\Throwable $e) {
            return 'Error performing web search: '.$e->getMessage();
        }
    }

    private function parseResults(string $html, string $query): string
    {
        $results = [];

        preg_match_all(
            '/<a[^>]*class="result__a"[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/si',
            $html,
            $linkMatches,
            PREG_SET_ORDER
        );

        preg_match_all(
            '/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/si',
            $html,
            $snippetMatches
        );

        $maxResults = 5;

        foreach ($linkMatches as $i => $match) {
            if ($i >= $maxResults) {
                break;
            }

            $url = $this->extractUrl($match[1]);
            $title = strip_tags($match[2]);
            $snippet = isset($snippetMatches[1][$i]) ? strip_tags($snippetMatches[1][$i]) : '';

            $results[] = ($i + 1).". {$title}\n   URL: {$url}\n   {$snippet}";
        }

        if (empty($results)) {
            return 'No results found for: '.$query;
        }

        return "Search results for \"{$query}\":\n\n".implode("\n\n", $results);
    }

    private function extractUrl(string $ddgUrl): string
    {
        if (preg_match('/uddg=([^&]+)/', $ddgUrl, $m)) {
            return urldecode($m[1]);
        }

        return $ddgUrl;
    }
}
