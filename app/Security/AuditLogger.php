<?php

namespace App\Security;

use App\Enums\AuditLogResult;
use App\Models\AuditLog;

class AuditLogger
{
    public function log(string $action, ?string $toolName, array $parameters, string $result, ?int $conversationId = null): AuditLog
    {
        $auditResult = AuditLogResult::tryFrom($result) ?? AuditLogResult::Error;

        $previousSignature = AuditLog::query()
            ->orderByDesc('id')
            ->value('signature');

        $data = [
            'conversation_id' => $conversationId,
            'action' => $action,
            'tool_name' => $toolName,
            'parameters' => $parameters,
            'result' => $auditResult,
            'ip_address' => request()?->ip(),
            'previous_signature' => $previousSignature,
        ];

        $data['signature'] = $this->computeSignature($data);

        return AuditLog::query()->create($data);
    }

    public function verify(AuditLog $log): bool
    {
        $data = [
            'conversation_id' => $log->conversation_id,
            'action' => $log->action,
            'tool_name' => $log->tool_name,
            'parameters' => $log->parameters,
            'result' => $log->result,
            'ip_address' => $log->ip_address,
            'previous_signature' => $log->previous_signature,
        ];

        return hash_equals($log->signature ?? '', $this->computeSignature($data));
    }

    /**
     * @return array{valid: bool, total: int, verified: int, first_failure: int|null}
     */
    public function verifyChain(): array
    {
        $logs = AuditLog::query()->orderBy('id')->get();
        $verified = 0;

        foreach ($logs as $log) {
            if (! $this->verify($log)) {
                return [
                    'valid' => false,
                    'total' => $logs->count(),
                    'verified' => $verified,
                    'first_failure' => $log->id,
                ];
            }

            $verified++;
        }

        return [
            'valid' => true,
            'total' => $logs->count(),
            'verified' => $verified,
            'first_failure' => null,
        ];
    }

    private function computeSignature(array $data): string
    {
        return hash_hmac('sha256', serialize($data), config('app.key'));
    }
}
