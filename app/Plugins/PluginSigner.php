<?php

namespace App\Plugins;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class PluginSigner
{
    public function __construct(private readonly PluginVerifier $pluginVerifier) {}

    public function generateKeyPair(): array
    {
        $this->assertSodiumAvailable();

        $keyPair = sodium_crypto_sign_keypair();

        return [
            'public_key' => sodium_bin2hex(sodium_crypto_sign_publickey($keyPair)),
            'secret_key' => sodium_bin2hex(sodium_crypto_sign_secretkey($keyPair)),
        ];
    }

    public function writeDefaultKeyPair(): array
    {
        $keyPair = $this->generateKeyPair();
        $secretKeyPath = $this->secretKeyPath();
        $publicKeyPath = $this->publicKeyPath();
        $directories = array_unique([dirname($secretKeyPath), dirname($publicKeyPath)]);

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                File::makeDirectory($directory, 0755, true);
            }
        }

        File::put($secretKeyPath, $keyPair['secret_key']);
        File::put($publicKeyPath, $keyPair['public_key']);

        @chmod($secretKeyPath, 0600);
        @chmod($publicKeyPath, 0644);

        return [
            'secret_key_path' => $secretKeyPath,
            'public_key_path' => $publicKeyPath,
            'public_key' => $keyPair['public_key'],
        ];
    }

    public function signPath(string $pluginPath): array
    {
        $this->assertSodiumAvailable();

        PluginManifest::fromPath($pluginPath);

        $manifestPath = rtrim($pluginPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'plugin.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            throw new InvalidArgumentException("Plugin manifest at [{$manifestPath}] is not valid JSON.");
        }

        $secretKeyPath = $this->secretKeyPath();

        if (! is_file($secretKeyPath)) {
            throw new InvalidArgumentException('Plugin signing key is missing. Run [aegis:plugin:keygen] first.');
        }

        $secretKeyHex = strtolower(trim((string) File::get($secretKeyPath)));

        if (! $this->isValidSecretKeyHex($secretKeyHex)) {
            throw new InvalidArgumentException('Plugin signing key is missing or invalid. Run [aegis:plugin:keygen] first.');
        }

        $hash = $this->pluginVerifier->hashPath($pluginPath);
        $secretKey = sodium_hex2bin($secretKeyHex);
        $signature = sodium_bin2hex(sodium_crypto_sign_detached($hash, $secretKey));
        $publicKey = sodium_bin2hex(sodium_crypto_sign_publickey_from_secretkey($secretKey));

        $manifest['signature'] = $signature;
        $manifest['public_key'] = $publicKey;

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new InvalidArgumentException("Plugin manifest at [{$manifestPath}] could not be encoded.");
        }

        File::put($manifestPath, $encoded);

        return [
            'path' => rtrim($pluginPath, DIRECTORY_SEPARATOR),
            'hash' => $hash,
            'signature' => $signature,
            'public_key' => $publicKey,
        ];
    }

    public function secretKeyPath(): string
    {
        return (string) config(
            'aegis.plugins.signing.secret_key_path',
            storage_path('app/plugins/signing/ed25519.secret'),
        );
    }

    public function publicKeyPath(): string
    {
        return (string) config(
            'aegis.plugins.signing.public_key_path',
            storage_path('app/plugins/signing/ed25519.public'),
        );
    }

    private function isValidSecretKeyHex(string $secretKeyHex): bool
    {
        return strlen($secretKeyHex) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES * 2
            && ctype_xdigit($secretKeyHex);
    }

    private function assertSodiumAvailable(): void
    {
        if (! function_exists('sodium_crypto_sign_detached') || ! function_exists('sodium_crypto_sign_keypair')) {
            throw new InvalidArgumentException('Sodium extension is required for plugin signing.');
        }
    }
}
