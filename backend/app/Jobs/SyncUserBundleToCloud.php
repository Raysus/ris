<?php

namespace App\Jobs;

use App\Models\CloudSyncLog;
use App\Models\User;
use App\Services\CloudSyncLogger;
use App\Support\CloudSyncMode;
use App\Support\CloudSyncTransport;
use App\Support\RisHttp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envía persona → usuario (y pivotes de sede) a la nube en orden garantizado.
 */
class SyncUserBundleToCloud implements ShouldQueue, ShouldQueueAfterCommit, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $userId;

    public string $action;

    public ?string $syncLogId = null;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(string $userId, string $action = 'updated', ?string $syncLogId = null)
    {
        $this->userId = $userId;
        $this->action = $action;
        $this->syncLogId = $syncLogId;
    }

    public function uniqueId(): string
    {
        return 'user-bundle:' . $this->action . ':' . $this->userId;
    }

    public function handle(): void
    {
        if (!CloudSyncMode::canPushToCloud()) {
            return;
        }

        $user = User::with(['persona', 'tipoUsuario', 'laboratories'])->find($this->userId);
        if (!$user?->persona) {
            return;
        }

        $persona = $user->persona->toArray();
        $userData = $user->makeVisible(['password'])->toArray();
        $userData['persona'] = $persona;

        if (!$this->syncLogId) {
            $this->syncLogId = CloudSyncLogger::startPending('App\Models\User', $this->action, $userData)->id;
        } else {
            CloudSyncLogger::markAttempt($this->syncLogId);
        }

        $cloudUrl = config('cloud_sync.inbound_url');
        $secret = config('cloud_sync.secret');

        $http = RisHttp::client(CloudSyncTransport::defaultTimeout())->withToken($secret)->acceptJson()->asJson();

        $headers = [
            'User-Agent' => 'HealthTiCloud-RIS/1.0',
            'X-Requested-With' => 'XMLHttpRequest',
        ];

        $chunks = [
            ['model' => 'App\Models\Persona', 'action' => 'updated', 'data' => $persona],
            [
                'model' => 'App\Models\User',
                'action' => $this->action,
                'data' => $userData,
            ],
        ];

        try {
            foreach ($chunks as $chunk) {
                $response = $http->withHeaders($headers)->post($cloudUrl, $chunk);
                if ($response->failed()) {
                    $error = CloudSyncTransport::exceptionFromResponse(
                        $response,
                        'Sync bundle falló en ' . $chunk['model']
                    );

                    if ($this->deferTransientFailure($error, $response->status())) {
                        return;
                    }

                    throw $error;
                }
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

        Log::info('Cloud sync bundle de usuario diferido', [
            'user_id' => $this->userId,
            'sync_log_id' => $this->syncLogId,
            'delay_seconds' => $delay,
            'error' => $e->getMessage(),
        ]);

        $this->release($delay);

        return true;
    }
}
