<?php

namespace App\Security;

use App\Enums\ToolPermissionLevel;
use App\Models\ToolPermission;

class PermissionManager
{
    public function check(string $toolName, string $permission, ?array $context = null): PermissionDecision
    {
        $context = $context ?? [];

        if ($this->containsBlockedPath($context)) {
            return PermissionDecision::Denied;
        }

        if ($this->containsCommandInjection($context)) {
            return PermissionDecision::Denied;
        }

        $scope = $this->scopeFromContext($context);
        $persisted = $this->resolvePermission($toolName, $scope);

        if ($persisted === ToolPermissionLevel::Allow) {
            return PermissionDecision::Allowed;
        }

        if ($persisted === ToolPermissionLevel::Deny) {
            return PermissionDecision::Denied;
        }

        if ($persisted === ToolPermissionLevel::Ask) {
            return PermissionDecision::NeedsApproval;
        }

        if ($permission === 'read' && (bool) config('aegis.security.auto_allow_read', true)) {
            return PermissionDecision::Allowed;
        }

        return PermissionDecision::NeedsApproval;
    }

    public function remember(string $toolName, ToolPermissionLevel $permission, ?string $scope = null): ToolPermission
    {
        return ToolPermission::query()->updateOrCreate(
            [
                'tool_name' => $toolName,
                'scope' => $scope ?? 'global',
            ],
            [
                'permission' => $permission,
                'expires_at' => null,
            ],
        );
    }

    public function scopeFromContext(array $context): string
    {
        $scope = $context['scope'] ?? null;

        if (! is_string($scope) || $scope === '') {
            return 'global';
        }

        return $scope;
    }

    private function resolvePermission(string $toolName, string $scope): ?ToolPermissionLevel
    {
        $candidates = [$scope, 'global', null];

        foreach ($candidates as $candidateScope) {
            $record = ToolPermission::query()
                ->where('tool_name', $toolName)
                ->when(
                    $candidateScope === null,
                    fn ($query) => $query->whereNull('scope'),
                    fn ($query) => $query->where('scope', $candidateScope)
                )
                ->latest('id')
                ->first();

            if ($record === null || $record->isExpired()) {
                continue;
            }

            return $record->permission;
        }

        return null;
    }

    private function containsBlockedPath(array $context): bool
    {
        foreach ($context as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (! str_contains(strtolower((string) $key), 'path')) {
                continue;
            }

            if ($this->isBlockedPath($value)) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedPath(string $path): bool
    {
        $normalized = strtolower(trim(str_replace('\\', '/', $path)));

        if (str_contains($normalized, '..')) {
            return true;
        }

        foreach (['/etc', '/sys', '/proc'] as $blockedPrefix) {
            if ($normalized === $blockedPrefix || str_starts_with($normalized, $blockedPrefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function containsCommandInjection(array $context): bool
    {
        foreach ($context as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (! str_contains(strtolower((string) $key), 'command')) {
                continue;
            }

            if (preg_match('/(;|&&|\|\||`|\$\()/', $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
