<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AuditLogger
{
    public static function record(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $details = [],
        ?Request $request = null,
    ): void {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        try {
            $request ??= request();

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details ?: null,
                'ip_address' => $request?->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditLogger: no se pudo registrar evento', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
