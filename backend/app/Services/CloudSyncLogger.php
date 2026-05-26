<?php

namespace App\Services;

use App\Models\CloudSyncLog;
use Illuminate\Support\Str;

class CloudSyncLogger
{
    public static function startPending(string $entityType, string $action, array $payload): CloudSyncLog
    {
        $entityId = $payload['id'] ?? null;

        return CloudSyncLog::create([
            'entity_type' => $entityType,
            'action' => $action,
            'entity_id' => is_string($entityId) && Str::isUuid($entityId) ? $entityId : null,
            'status' => 'pending',
            'attempts' => 0,
            'payload_hash' => self::hashPayload($payload),
            'payload' => self::trimPayload($payload),
        ]);
    }

    public static function markAttempt(CloudSyncLog|string $log): void
    {
        $record = is_string($log) ? CloudSyncLog::find($log) : $log;
        if (!$record) {
            return;
        }

        $record->update([
            'attempts' => $record->attempts + 1,
            'status' => 'pending',
        ]);
    }

    public static function markSuccess(CloudSyncLog|string $log): void
    {
        $record = is_string($log) ? CloudSyncLog::find($log) : $log;
        if (!$record) {
            return;
        }

        $record->update([
            'status' => 'success',
            'synced_at' => now(),
            'last_error' => null,
        ]);
    }

    public static function markFailed(CloudSyncLog|string $log, \Throwable|string $error): void
    {
        $record = is_string($log) ? CloudSyncLog::find($log) : $log;
        if (!$record) {
            return;
        }

        $message = is_string($error) ? $error : $error->getMessage();

        $record->update([
            'status' => 'failed',
            'last_error' => Str::limit($message, 2000),
        ]);
    }

    public static function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode(self::trimPayload($payload)));
    }

    /**
     * Evita guardar base64 de archivos en el log de sync.
     */
    public static function trimPayload(array $payload): array
    {
        $copy = $payload;

        foreach (array_keys($copy) as $key) {
            if (str_ends_with($key, '_base64')) {
                unset($copy[$key]);
            }
        }

        if (isset($copy['studies']) && is_array($copy['studies'])) {
            foreach ($copy['studies'] as $i => $study) {
                if (is_array($study)) {
                    foreach (array_keys($study) as $sk) {
                        if (str_ends_with($sk, '_base64')) {
                            unset($copy['studies'][$i][$sk]);
                        }
                    }
                }
            }
        }

        return $copy;
    }
}
