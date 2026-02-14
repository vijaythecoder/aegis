<?php

namespace App\Livewire;

use App\Enums\ToolPermissionLevel;
use App\Events\ApprovalResponse;
use App\Security\PermissionManager;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Component;

class PermissionDialog extends Component
{
    public bool $isVisible = false;

    public ?string $requestId = null;

    public string $toolName = '';

    public string $permission = '';

    public array $parameters = [];

    public ?string $scope = null;

    public int $expiresAt = 0;

    #[On('approval-dialog.open')]
    public function open(string $requestId, string $toolName, string $permission, array $parameters = [], ?string $scope = null): void
    {
        $timeout = (int) config('aegis.security.approval_timeout', 60);

        $this->requestId = $requestId;
        $this->toolName = $toolName;
        $this->permission = $permission;
        $this->parameters = $parameters;
        $this->scope = $scope;
        $this->expiresAt = now()->addSeconds($timeout)->timestamp;
        $this->isVisible = true;
    }

    public function allowOnce(): void
    {
        $this->respond('allow', false);
    }

    public function alwaysAllow(): void
    {
        $this->respond('allow', true);
    }

    public function deny(): void
    {
        $this->respond('deny', false);
    }

    public function checkTimeout(): void
    {
        if (! $this->isVisible) {
            return;
        }

        if (now()->timestamp >= $this->expiresAt) {
            $this->deny();
        }
    }

    public function render()
    {
        return view('livewire.permission-dialog');
    }

    private function respond(string $decision, bool $remember): void
    {
        if ($this->requestId === null) {
            return;
        }

        if ($remember && $decision === 'allow') {
            app(PermissionManager::class)->remember(
                $this->toolName,
                ToolPermissionLevel::Allow,
                $this->scope ?? 'global',
            );
        }

        Cache::put($this->cacheKey($this->requestId), [
            'decision' => $decision,
            'remember' => $remember,
        ], now()->addMinutes(5));

        event(new ApprovalResponse($this->requestId, $decision, $remember));
        $this->close();
    }

    private function close(): void
    {
        $this->isVisible = false;
        $this->requestId = null;
        $this->toolName = '';
        $this->permission = '';
        $this->parameters = [];
        $this->scope = null;
        $this->expiresAt = 0;
    }

    private function cacheKey(string $requestId): string
    {
        return 'approval-response:'.$requestId;
    }
}
