<?php

namespace App\Desktop;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateService
{
    private ?array $latestRelease = null;

    public function currentVersion(): string
    {
        return (string) config('nativephp.version', '1.0.0');
    }

    public function checkForUpdate(): ?array
    {
        $release = $this->fetchLatestRelease();

        if ($release === null) {
            return null;
        }

        $latestVersion = ltrim((string) ($release['tag_name'] ?? ''), 'v');

        if ($latestVersion === '' || ! $this->isNewerVersion($latestVersion)) {
            return null;
        }

        return [
            'current_version' => $this->currentVersion(),
            'latest_version' => $latestVersion,
            'release_notes' => (string) ($release['body'] ?? ''),
            'download_url' => (string) ($release['html_url'] ?? ''),
            'published_at' => (string) ($release['published_at'] ?? ''),
        ];
    }

    public function isAutoUpdateEnabled(): bool
    {
        $setting = Setting::query()
            ->where('group', 'general')
            ->where('key', 'auto_update_enabled')
            ->value('value');

        if ($setting === null) {
            return (bool) config('nativephp.updater.enabled', true);
        }

        return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }

    public function setAutoUpdateEnabled(bool $enabled): void
    {
        Setting::query()->updateOrCreate(
            ['group' => 'general', 'key' => 'auto_update_enabled'],
            ['value' => $enabled ? '1' : '0'],
        );
    }

    public function updateChannel(): string
    {
        $channel = Setting::query()
            ->where('group', 'general')
            ->where('key', 'update_channel')
            ->value('value');

        return in_array($channel, ['stable', 'beta'], true) ? $channel : 'stable';
    }

    public function setUpdateChannel(string $channel): void
    {
        $normalized = in_array($channel, ['stable', 'beta'], true) ? $channel : 'stable';

        Setting::query()->updateOrCreate(
            ['group' => 'general', 'key' => 'update_channel'],
            ['value' => $normalized],
        );
    }

    public function shouldCheckForUpdates(): bool
    {
        if (! $this->isAutoUpdateEnabled()) {
            return false;
        }

        $lastCheck = Setting::query()
            ->where('group', 'general')
            ->where('key', 'last_update_check')
            ->value('value');

        if ($lastCheck === null) {
            return true;
        }

        $intervalSeconds = (int) config('aegis.update_check_interval', 86400);

        return now()->timestamp - (int) $lastCheck >= $intervalSeconds;
    }

    public function recordCheckTimestamp(): void
    {
        Setting::query()->updateOrCreate(
            ['group' => 'general', 'key' => 'last_update_check'],
            ['value' => (string) now()->timestamp],
        );
    }

    public function triggerNativeUpdate(): bool
    {
        if (! class_exists('Native\Laravel\Facades\AutoUpdater')) {
            Log::info('aegis.update.native_unavailable');

            return false;
        }

        try {
            \Native\Laravel\Facades\AutoUpdater::checkForUpdates();

            return true;
        } catch (Throwable $e) {
            Log::warning('aegis.update.check_failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function triggerNativeInstall(): bool
    {
        if (! class_exists('Native\Laravel\Facades\AutoUpdater')) {
            return false;
        }

        try {
            \Native\Laravel\Facades\AutoUpdater::quitAndInstall();

            return true;
        } catch (Throwable $e) {
            Log::warning('aegis.update.install_failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function fetchLatestRelease(): ?array
    {
        if ($this->latestRelease !== null) {
            return $this->latestRelease;
        }

        $owner = (string) config('nativephp.updater.providers.github.owner', '');
        $repo = (string) config('nativephp.updater.providers.github.repo', '');

        if ($owner === '' || $repo === '') {
            return null;
        }

        $channel = $this->updateChannel();

        try {
            $url = $channel === 'beta'
                ? "https://api.github.com/repos/{$owner}/{$repo}/releases"
                : "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";

            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            if ($channel === 'beta' && is_array($data)) {
                $this->latestRelease = $data[0] ?? null;
            } else {
                $this->latestRelease = is_array($data) ? $data : null;
            }

            return $this->latestRelease;
        } catch (Throwable $e) {
            Log::warning('aegis.update.fetch_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function isNewerVersion(string $latest): bool
    {
        return version_compare($latest, $this->currentVersion(), '>');
    }
}
