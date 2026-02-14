<?php

namespace App\Plugins;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class PluginVerifier
{
    public const STATUS_VALID = 'valid';

    public const STATUS_UNSIGNED = 'unsigned';

    public const STATUS_TAMPERED = 'tampered';

    public const TRUST_VERIFIED_BY_AEGIS = 'verified_by_aegis';

    public const TRUST_AUTHOR_SIGNED = 'author_signed';

    public const TRUST_UNSIGNED = 'unsigned';

    public function verifyPath(string $pluginPath): array
    {
        $manifest = PluginManifest::fromPath($pluginPath);
        $hash = $this->hashPath($pluginPath);

        if ($manifest->signature === '' || $manifest->publicKey === '') {
            return [
                'status' => self::STATUS_UNSIGNED,
                'trust_level' => self::TRUST_UNSIGNED,
                'valid' => true,
                'tampered' => false,
                'hash' => $hash,
            ];
        }

        if (! $this->isValidPublicKeyHex($manifest->publicKey) || ! $this->isValidSignatureHex($manifest->signature)) {
            return [
                'status' => self::STATUS_TAMPERED,
                'trust_level' => $this->trustLevelForPublicKey($manifest->publicKey),
                'valid' => false,
                'tampered' => true,
                'hash' => $hash,
            ];
        }

        $this->assertSodiumAvailable();

        $isValid = sodium_crypto_sign_verify_detached(
            sodium_hex2bin($manifest->signature),
            $hash,
            sodium_hex2bin($manifest->publicKey),
        );

        if (! $isValid) {
            return [
                'status' => self::STATUS_TAMPERED,
                'trust_level' => $this->trustLevelForPublicKey($manifest->publicKey),
                'valid' => false,
                'tampered' => true,
                'hash' => $hash,
            ];
        }

        return [
            'status' => self::STATUS_VALID,
            'trust_level' => $this->trustLevelForPublicKey($manifest->publicKey),
            'valid' => true,
            'tampered' => false,
            'hash' => $hash,
        ];
    }

    public function hashPath(string $pluginPath): string
    {
        $root = rtrim($pluginPath, DIRECTORY_SEPARATOR);

        if (! is_dir($root)) {
            throw new InvalidArgumentException("Plugin path [{$pluginPath}] does not exist.");
        }

        $files = [];

        foreach (File::allFiles($root) as $file) {
            $absolute = $file->getPathname();
            $relative = str_replace('\\', '/', ltrim(substr($absolute, strlen($root)), DIRECTORY_SEPARATOR));

            if ($this->isTransientFile($relative)) {
                continue;
            }

            $files[$relative] = $absolute;
        }

        ksort($files);

        $context = hash_init('sha256');

        foreach ($files as $relative => $absolute) {
            $payload = $relative === 'plugin.json'
                ? $this->manifestHashPayload($absolute)
                : (string) file_get_contents($absolute);

            hash_update($context, $relative."\n");
            hash_update($context, hash('sha256', $payload, true));
        }

        return hash_final($context);
    }

    public function trustedPublicKeyPath(): string
    {
        return (string) config(
            'aegis.plugins.signing.public_key_path',
            storage_path('app/plugins/signing/ed25519.public'),
        );
    }

    private function manifestHashPayload(string $manifestPath): string
    {
        $decoded = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Plugin manifest at [{$manifestPath}] is not valid JSON.");
        }

        unset($decoded['signature'], $decoded['public_key']);

        $encoded = json_encode($this->normalizeForHash($decoded), JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new InvalidArgumentException("Plugin manifest at [{$manifestPath}] could not be encoded.");
        }

        return $encoded;
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForHash($item);
        }

        return $value;
    }

    private function isTransientFile(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        $transientDirectories = ['.git', '.svn', '.hg', 'node_modules'];

        foreach ($segments as $segment) {
            if (in_array($segment, $transientDirectories, true)) {
                return true;
            }
        }

        $filename = basename($relativePath);

        if (in_array($filename, ['.DS_Store', 'Thumbs.db'], true)) {
            return true;
        }

        return str_ends_with($filename, '.swp')
            || str_ends_with($filename, '.tmp')
            || str_ends_with($filename, '.bak');
    }

    private function trustLevelForPublicKey(string $publicKeyHex): string
    {
        $trusted = $this->trustedPublicKey();

        if ($trusted !== null && hash_equals($trusted, strtolower($publicKeyHex))) {
            return self::TRUST_VERIFIED_BY_AEGIS;
        }

        return self::TRUST_AUTHOR_SIGNED;
    }

    private function trustedPublicKey(): ?string
    {
        $path = $this->trustedPublicKeyPath();

        if (! is_file($path)) {
            return null;
        }

        $publicKey = strtolower(trim((string) File::get($path)));

        return $this->isValidPublicKeyHex($publicKey) ? $publicKey : null;
    }

    private function isValidPublicKeyHex(string $publicKeyHex): bool
    {
        return strlen($publicKeyHex) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES * 2
            && ctype_xdigit($publicKeyHex);
    }

    private function isValidSignatureHex(string $signatureHex): bool
    {
        return strlen($signatureHex) === SODIUM_CRYPTO_SIGN_BYTES * 2
            && ctype_xdigit($signatureHex);
    }

    private function assertSodiumAvailable(): void
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new InvalidArgumentException('Sodium extension is required for plugin verification.');
        }
    }
}
