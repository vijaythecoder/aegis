<?php

namespace App\Plugins;

use InvalidArgumentException;

class PluginManifest
{
    public readonly string $name;

    public readonly string $version;

    public readonly string $description;

    public readonly string $author;

    public readonly array $permissions;

    public readonly string $provider;

    public readonly array $tools;

    public readonly string $path;

    public readonly array $autoload;

    public readonly string $signature;

    public readonly string $publicKey;

    private function __construct(private readonly array $data, string $path)
    {
        $this->path = $path;
        $this->name = (string) ($data['name'] ?? '');
        $this->version = (string) ($data['version'] ?? '');
        $this->description = (string) ($data['description'] ?? '');
        $this->author = (string) ($data['author'] ?? '');
        $this->permissions = array_values(array_filter((array) ($data['permissions'] ?? []), 'is_string'));
        $this->provider = (string) ($data['provider'] ?? '');
        $this->tools = array_values(array_filter((array) ($data['tools'] ?? []), 'is_string'));
        $this->autoload = is_array($data['autoload'] ?? null) ? $data['autoload'] : [];
        $this->signature = is_string($data['signature'] ?? null) ? (string) $data['signature'] : '';
        $this->publicKey = is_string($data['public_key'] ?? null) ? (string) $data['public_key'] : '';
    }

    public static function fromPath(string $pluginPath): self
    {
        $manifestPath = rtrim($pluginPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'plugin.json';

        if (! is_file($manifestPath)) {
            throw new InvalidArgumentException("Plugin manifest not found at [{$manifestPath}].");
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Plugin manifest at [{$manifestPath}] is not valid JSON.");
        }

        $manifest = new self($decoded, rtrim($pluginPath, DIRECTORY_SEPARATOR));
        $errors = $manifest->validate();

        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid plugin manifest: '.implode('; ', $errors));
        }

        return $manifest;
    }

    public function validate(): array
    {
        $errors = [];

        if ($this->name === '') {
            $errors[] = 'Field [name] is required';
        }

        if ($this->version === '') {
            $errors[] = 'Field [version] is required';
        }

        if ($this->description === '') {
            $errors[] = 'Field [description] is required';
        }

        if ($this->author === '') {
            $errors[] = 'Field [author] is required';
        }

        if ($this->provider === '') {
            $errors[] = 'Field [provider] is required';
        }

        if (! isset($this->data['permissions']) || ! is_array($this->data['permissions'])) {
            $errors[] = 'Field [permissions] must be an array';
        }

        if (! isset($this->data['tools']) || ! is_array($this->data['tools'])) {
            $errors[] = 'Field [tools] must be an array';
        }

        if (array_key_exists('signature', $this->data) && ! is_string($this->data['signature'])) {
            $errors[] = 'Field [signature] must be a string';
        }

        if (array_key_exists('public_key', $this->data) && ! is_string($this->data['public_key'])) {
            $errors[] = 'Field [public_key] must be a string';
        }

        return $errors;
    }

    public function isValid(): bool
    {
        return $this->validate() === [];
    }
}
