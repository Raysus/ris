<?php

namespace App\Jobs;

use App\Models\CloudSyncLog;
use App\Services\CloudEntitySyncService;
use App\Services\CloudSyncLogger;
use App\Support\CloudSyncMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncEntityToCloud implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $entityType;
    public $action;
    public $payload;
    public ?string $syncLogId;

    public $tries = 5;
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

        $http = Http::timeout(15);

        if (app()->environment('local', 'testing')) {
            $http = $http->withoutVerifying();
        }

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
                throw new \Exception(
                    "Fallo al sincronizar {$this->entityType}. Nube respondió: " . $response->body()
                );
            }

            if ($this->syncLogId) {
                CloudSyncLogger::markSuccess($this->syncLogId);
            }
        } catch (\Throwable $e) {
            if ($this->syncLogId) {
                CloudSyncLogger::markFailed($this->syncLogId, $e);
            }
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->syncLogId) {
            CloudSyncLogger::markFailed($this->syncLogId, $exception);
        }
    }

    private function packFiles(): void
    {
        $columns = ['medical_order_path', 'survey_path', 'signature_path', 'audio_path'];

        foreach ($columns as $col) {
            if (!empty($this->payload[$col])) {
                $base64 = $this->fileToBase64($this->payload[$col]);
                if ($base64) {
                    $this->payload[$col . '_base64'] = $base64;
                }
            }
        }

        if (isset($this->payload['studies']) && is_array($this->payload['studies'])) {
            foreach ($this->payload['studies'] as $key => $study) {
                if (!empty($study['audio_path'])) {
                    $base64 = $this->fileToBase64($study['audio_path']);
                    if ($base64) {
                        $this->payload['studies'][$key]['audio_path_base64'] = $base64;
                    }
                }
            }
        }
    }

    private function fileToBase64($path)
    {
        $cleanPath = str_replace('/storage/', '', $path);

        if (Storage::disk('public')->exists($cleanPath)) {
            $content = Storage::disk('public')->get($cleanPath);
            $absolutePath = Storage::disk('public')->path($cleanPath);
            $mime = mime_content_type($absolutePath);

            return 'data:' . $mime . ';base64,' . base64_encode($content);
        }

        return null;
    }
}
