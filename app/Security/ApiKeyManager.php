<?php

namespace App\Security;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

class ApiKeyManager
{
    private const GROUP = 'credentials';

    public function __construct(private readonly ProviderConfig $providerConfig) {}

    public function store(string $provider, string $key): void
    {
        if (! $this->providerConfig->validate($provider, $key)) {
            throw new InvalidArgumentException("Invalid API key format for provider [{$provider}].");
        }

        Setting::query()->updateOrCreate(
            [
                'group' => self::GROUP,
                'key' => $this->settingKey($provider),
            ],
            [
                'value' => Crypt::encryptString(trim($key)),
                'is_encrypted' => true,
            ],
        );
    }

    public function retrieve(string $provider): ?string
    {
        $setting = $this->setting($provider);

        if (! $setting) {
            return null;
        }

        return $setting->decrypted_value;
    }

    public function delete(string $provider): void
    {
        Setting::query()
            ->where('group', self::GROUP)
            ->where('key', $this->settingKey($provider))
            ->delete();
    }

    public function list(): array
    {
        $providers = [];

        foreach ($this->providerConfig->providers() as $provider => $config) {
            $value = $this->retrieve($provider);

            $providers[$provider] = [
                'name' => $config['name'],
                'is_set' => $value !== null && $value !== '',
                'requires_key' => $this->providerConfig->requiresKey($provider),
                'masked' => $value ? $this->mask($value) : null,
            ];
        }

        return $providers;
    }

    public function has(string $provider): bool
    {
        return Setting::query()
            ->where('group', self::GROUP)
            ->where('key', $this->settingKey($provider))
            ->exists();
    }

    private function settingKey(string $provider): string
    {
        if (! $this->providerConfig->hasProvider($provider)) {
            throw new InvalidArgumentException("Unsupported provider [{$provider}].");
        }

        return $provider.'_api_key';
    }

    private function setting(string $provider): ?Setting
    {
        return Setting::query()
            ->where('group', self::GROUP)
            ->where('key', $this->settingKey($provider))
            ->first();
    }

    private function mask(string $key): string
    {
        $prefix = substr($key, 0, 3);
        $suffix = substr($key, -4);

        return $prefix.'...'.$suffix;
    }
}
