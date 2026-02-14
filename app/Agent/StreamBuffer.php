<?php

namespace App\Agent;

use Illuminate\Support\Facades\Cache;

class StreamBuffer
{
    public function __construct(private readonly string $conversationId) {}

    public function start(): void
    {
        $state = $this->state();
        $state['active'] = true;
        $state['cancelled'] = false;
        $this->store($state);
    }

    public function append(string $token): void
    {
        if ($token === '') {
            return;
        }

        $state = $this->state();
        $state['tokens'] .= $token;
        $state['offset'] = mb_strlen($state['tokens']);
        $state['writes']++;
        $state['active'] = true;

        $this->store($state);
    }

    public function read(): string
    {
        return (string) $this->state()['tokens'];
    }

    public function readNew(int $fromOffset): string
    {
        $state = $this->state();

        return mb_substr((string) $state['tokens'], max(0, $fromOffset));
    }

    public function isActive(): bool
    {
        return (bool) $this->state()['active'];
    }

    public function isCancelled(): bool
    {
        return (bool) $this->state()['cancelled'];
    }

    public function complete(): void
    {
        $state = $this->state();
        $state['active'] = false;
        $this->store($state);
    }

    public function cancel(): void
    {
        $state = $this->state();
        $state['active'] = false;
        $state['cancelled'] = true;
        $this->store($state);
    }

    public function clear(): void
    {
        Cache::forget($this->key());
    }

    private function state(): array
    {
        return Cache::get($this->key(), [
            'tokens' => '',
            'active' => false,
            'cancelled' => false,
            'offset' => 0,
            'writes' => 0,
        ]);
    }

    private function store(array $state): void
    {
        Cache::put($this->key(), $state, now()->addMinutes(30));
    }

    private function key(): string
    {
        return 'stream:'.$this->conversationId;
    }
}
