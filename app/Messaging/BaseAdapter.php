<?php

namespace App\Messaging;

use App\Messaging\Contracts\MessagingAdapter;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

abstract class BaseAdapter implements MessagingAdapter
{
    protected function splitMessage(string $content, int $maxLength): array
    {
        if (mb_strlen($content) <= $maxLength) {
            return [$content];
        }

        $chunks = [];
        $remaining = $content;

        while ($remaining !== '') {
            if (mb_strlen($remaining) <= $maxLength) {
                $chunks[] = $remaining;
                break;
            }

            $candidate = mb_substr($remaining, 0, $maxLength);
            $breakAt = mb_strrpos($candidate, "\n");
            if ($breakAt === false || $breakAt < (int) ($maxLength * 0.5)) {
                $breakAt = mb_strrpos($candidate, ' ');
            }

            if ($breakAt === false || $breakAt === 0) {
                $breakAt = $maxLength;
            }

            $chunks[] = trim(mb_substr($remaining, 0, $breakAt));
            $remaining = ltrim(mb_substr($remaining, $breakAt));
        }

        return array_values(array_filter($chunks, fn (string $chunk): bool => $chunk !== ''));
    }

    protected function checkRateLimit(): bool
    {
        $window = max(1, (int) config('aegis.messaging.rate_limit_window_seconds', 60));
        $max = max(1, (int) config('aegis.messaging.rate_limit_max_requests', 60));
        $key = 'messaging:rate:'.static::class;

        $count = (int) Cache::get($key, 0);
        if ($count >= $max) {
            return false;
        }

        if ($count === 0) {
            Cache::put($key, 1, now()->addSeconds($window));

            return true;
        }

        Cache::increment($key);

        return true;
    }

    protected function safeExecute(Closure $action): mixed
    {
        try {
            return $action();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
