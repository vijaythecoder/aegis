<?php

namespace App\Marketplace;

use App\Models\MarketplacePlugin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class PluginRegistry
{
    public function sync(bool $force = false): Collection
    {
        if (! $force && $this->isCacheFresh()) {
            return $this->all();
        }

        $response = Http::timeout(15)->get($this->endpoint('/plugins'));

        if (! $response->successful()) {
            throw new InvalidArgumentException('Marketplace registry sync failed.');
        }

        $plugins = $response->json('data', $response->json());

        if (! is_array($plugins)) {
            throw new InvalidArgumentException('Marketplace registry returned invalid payload.');
        }

        foreach ($plugins as $plugin) {
            if (! is_array($plugin) || ! isset($plugin['name'])) {
                continue;
            }

            MarketplacePlugin::query()->updateOrCreate(
                ['name' => (string) $plugin['name']],
                [
                    'version' => (string) ($plugin['version'] ?? '0.0.0'),
                    'description' => (string) ($plugin['description'] ?? ''),
                    'author' => (string) ($plugin['author'] ?? ''),
                    'downloads' => (int) ($plugin['downloads'] ?? 0),
                    'rating' => (float) ($plugin['rating'] ?? 0),
                    'trust_tier' => (string) ($plugin['trust_tier'] ?? MarketplacePlugin::TRUST_UNVERIFIED),
                    'manifest_url' => (string) ($plugin['manifest_url'] ?? ''),
                    'checksum' => (string) ($plugin['checksum'] ?? ''),
                ],
            );
        }

        return $this->all();
    }

    public function search(?string $query = null): Collection
    {
        $trimmed = trim((string) $query);

        return MarketplacePlugin::query()
            ->when($trimmed !== '', function ($builder) use ($trimmed): void {
                $builder->where(function ($inner) use ($trimmed): void {
                    $inner->where('name', 'like', "%{$trimmed}%")
                        ->orWhere('description', 'like', "%{$trimmed}%")
                        ->orWhere('author', 'like', "%{$trimmed}%");
                });
            })
            ->orderByDesc('downloads')
            ->orderBy('name')
            ->get();
    }

    public function getPlugin(string $name): ?MarketplacePlugin
    {
        return MarketplacePlugin::query()->where('name', $name)->first();
    }

    public function all(): Collection
    {
        return MarketplacePlugin::query()
            ->orderByDesc('downloads')
            ->orderBy('name')
            ->get();
    }

    private function isCacheFresh(): bool
    {
        $latest = MarketplacePlugin::query()->max('updated_at');

        if (! $latest) {
            return false;
        }

        return now()->diffInSeconds($latest) < (int) config('aegis.marketplace.cache_ttl', 3600);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('aegis.marketplace.registry_url'), '/').$path;
    }
}
