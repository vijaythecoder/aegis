<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Security\AuditLogger;
use App\Security\PermissionManager;
use Illuminate\Support\Collection;
use Livewire\Component;

class SecurityDashboard extends Component
{
    public string $filterAction = '';

    public string $flashMessage = '';

    public string $flashType = 'success';

    public ?array $integrityResult = null;

    public function getAuditLogsProperty(): Collection
    {
        $query = AuditLog::query()->orderByDesc('id')->limit(50);

        if ($this->filterAction !== '') {
            $query->where('action', 'like', '%'.$this->filterAction.'%');
        }

        return $query->get();
    }

    public function getCapabilityTokensProperty(): Collection
    {
        return app(PermissionManager::class)->activeCapabilities();
    }

    public function verifyIntegrity(): void
    {
        $result = app(AuditLogger::class)->verifyChain();
        $this->integrityResult = $result;

        if ($result['valid']) {
            $this->flash('Audit chain integrity verified: '.$result['verified'].'/'.$result['total'].' entries valid.', 'success');
        } else {
            $this->flash('Integrity check failed at entry #'.$result['first_failure'].'.', 'error');
        }
    }

    public function revokeToken(int $tokenId): void
    {
        $revoked = app(PermissionManager::class)->revokeCapability($tokenId);

        if ($revoked) {
            $this->flash('Capability token revoked.', 'success');
        } else {
            $this->flash('Token not found.', 'error');
        }
    }

    public function render()
    {
        return view('livewire.security-dashboard');
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
