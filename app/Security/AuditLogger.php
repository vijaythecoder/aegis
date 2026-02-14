<?php

namespace App\Security;

use App\Enums\AuditLogResult;
use App\Models\AuditLog;

class AuditLogger
{
    public function log(string $action, ?string $toolName, array $parameters, string $result, ?int $conversationId = null): AuditLog
    {
        $auditResult = AuditLogResult::tryFrom($result) ?? AuditLogResult::Error;

        return AuditLog::query()->create([
            'conversation_id' => $conversationId,
            'action' => $action,
            'tool_name' => $toolName,
            'parameters' => $parameters,
            'result' => $auditResult,
            'ip_address' => request()?->ip(),
        ]);
    }
}
