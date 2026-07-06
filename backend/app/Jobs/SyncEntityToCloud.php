<?php

namespace App\Jobs;

use App\Models\CloudSyncLog;
use App\Services\CloudSyncFilePackager;
use App\Services\CloudEntitySyncService;
use App\Services\CloudSyncLogger;
use App\Support\CloudSyncMode;
use App\Support\CloudSyncTransport;
use App\Support\RisHttp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncEntityToCloud implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $entityType;
    public $action;
    public $payload;
    public ?string $syncLogId;

    /** Reintentos solo para errores permanentes; los de red usan release(). */
    public $tries = 3;
    public $backoff = 30;
    public int $uniqueFor = 120;

    public function __construct($entityType, $action, $payload, ?string $syncLogId = null)
    {
        $this->entityType = $entityType;
        $this->action = $action;
        $this->payload = $payload;

        if ($syncLogId) {
            $this->syncLogId = $syncLogId;
        } else {
            $this->syncLogId = CloudSyncLogger::startPending($entityType, $action, $payload)->id;
        }
    }

    public function uniqueId(): string
    {
        $entityId = is_array($this->payload) ? (string) ($this->payload['id'] ?? '') : '';
        if ($entityId === '') {
            $entityId = substr(md5(json_encode($this->payload)), 0, 16);
        }

        return strtolower(class_basename((string) $this->entityType)) . ':' . $this->action . ':' . $entityId;
    }

    public function handle(): void
    {
        if (CloudEntitySyncService::$applying) {
            return;
        }

        if ($this->syncLogId) {
            CloudSyncLogger::markAttempt($this->syncLogId);
        }

        $cloudUrl = config('cloud_sync.inbound_url');
        $secret = config('cloud_sync.secret');

        if (!CloudSyncMode::canPushToCloud()) {
            $msg = 'Sincronización cloud no configurada (CLOUD_API_BASE / CLOUD_SYNC_SECRET).';
            Log::debug($msg);
            if ($this->syncLogId) {
                CloudSyncLog::where('id', $this->syncLogId)->update([
                    'status' => 'skipped',
                    'last_error' => null,
                ]);
            }
            return;
        }

        $this->packFiles();

        $http = RisHttp::client(CloudSyncTransport::defaultTimeout());

        try {
            $response = $http
                ->withToken($secret)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'User-Agent' => 'HealthTiCloud-RIS/1.0',
                    'X-Requested-With' => 'XMLHttpRequest',
                ])
                ->post($cloudUrl, [
                    'model' => $this->entityType,
                    'action' => $this->action,
                    'data' => $this->payload,
                ]);

            if ($response->failed()) {
                $error = CloudSyncTransport::exceptionFromResponse(
                    $response,
                    "Fallo al sincronizar {$this->entityType}"
                );

                if ($this->deferTransientFailure($error, $response->status())) {
                    return;
                }

                throw $error;
            }

            if ($this->syncLogId) {
                CloudSyncLogger::markSuccess($this->syncLogId);
            }
        } catch (\Throwable $e) {
            if ($this->deferTransientFailure($e)) {
                return;
            }

            if ($this->syncLogId) {
                CloudSyncLogger::markFailed($this->syncLogId, $e);
            }
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->syncLogId && CloudSyncTransport::isTransient($exception)) {
            CloudSyncLogger::markDeferred($this->syncLogId, $exception);

            return;
        }

        if ($this->syncLogId) {
            CloudSyncLogger::markFailed($this->syncLogId, $exception);
        }
    }

    private function deferTransientFailure(\Throwable $e, ?int $httpStatus = null): bool
    {
        $transient = $httpStatus !== null
            ? CloudSyncTransport::isTransientHttpStatus($httpStatus)
            : CloudSyncTransport::isTransient($e);

        if (!$transient || CloudSyncTransport::isPermanentHttpStatus($httpStatus)) {
            return false;
        }

        if ($this->syncLogId) {
            CloudSyncLogger::markDeferred($this->syncLogId, $e);
        }

        $attempts = $this->syncLogId
            ? (int) CloudSyncLog::whereKey($this->syncLogId)->value('attempts')
            : $this->attempts();

        $delay = CloudSyncTransport::releaseDelaySeconds($attempts);

        Log::info('Cloud sync diferido (sin conectividad o nube no disponible)', [
            'entity_type' => $this->entityType,
            'action' => $this->action,
            'sync_log_id' => $this->syncLogId,
            'delay_seconds' => $delay,
            'error' => $e->getMessage(),
        ]);

        $this->release($delay);

        return true;
    }

    private function packFiles(): void
    {
        CloudSyncFilePackager::packEntityPayload($this->payload);
    }
}
