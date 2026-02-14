<?php

namespace App\Mobile;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MobilePairingService
{
    public function generatePairing(string $host, int $port, int $ttlMinutes = 5): array
    {
        $token = Str::random(64);

        $qrData = json_encode([
            'host' => $host,
            'port' => $port,
            'token' => $token,
        ], JSON_THROW_ON_ERROR);

        $expiresAt = now()->addMinutes($ttlMinutes);

        Cache::put("mobile:pairing:{$token}", [
            'host' => $host,
            'port' => $port,
            'created_at' => now()->toIso8601String(),
        ], $expiresAt);

        return [
            'token' => $token,
            'qr_data' => $qrData,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function validatePairing(string $token): bool
    {
        return Cache::has("mobile:pairing:{$token}");
    }

    public function consumePairing(string $token): ?array
    {
        $data = Cache::pull("mobile:pairing:{$token}");

        return is_array($data) ? $data : null;
    }

    public function createSession(string $deviceName): array
    {
        $sessionToken = Str::random(80);
        $deviceId = Str::uuid()->toString();

        Cache::put("mobile:session:{$sessionToken}", [
            'device_name' => $deviceName,
            'device_id' => $deviceId,
            'paired_at' => now()->toIso8601String(),
        ], now()->addDays(30));

        return [
            'session_token' => $sessionToken,
            'device_id' => $deviceId,
        ];
    }

    public function validateSession(string $sessionToken): ?array
    {
        $data = Cache::get("mobile:session:{$sessionToken}");

        return is_array($data) ? $data : null;
    }
}
