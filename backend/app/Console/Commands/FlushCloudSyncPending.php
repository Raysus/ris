<?php

namespace App\Console\Commands;

use App\Jobs\SyncAppointmentBundleToCloud;
use App\Jobs\SyncEntityToCloud;
use App\Models\CloudSyncLog;
use App\Services\CloudSyncRetryService;
use App\Support\CloudSyncMode;
use App\Support\CloudSyncTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FlushCloudSyncPending extends Command
{
    protected $signature = 'ris:flush-cloud-sync-pending
                            {--limit=100 : Máximo de registros a reencolar}
                            {--force : Reencolar aunque la nube no responda al health check}';

    protected $description = 'Reencola envíos a la nube pendientes (laboratorios sin internet).';

    public function handle(): int
    {
        if (!CloudSyncMode::isLocal() || !CloudSyncMode::canPushToCloud()) {
            $this->comment('No aplica: servidor no es laboratorio local con sync configurado.');

            return self::SUCCESS;
        }

        if (!$this->option('force') && !CloudSyncTransport::cloudReachable()) {
            $this->comment('Nube no alcanzable; los envíos siguen en estado pendiente.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $queued = 0;
        $staleMinutes = (int) config('cloud_sync.flush_stale_minutes', 2);

        $pending = CloudSyncLog::query()
            ->where('status', 'pending')
            ->whereNotNull('payload')
            ->where('updated_at', '<=', now()->subMinutes($staleMinutes))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($pending as $log) {
            if ($this->dispatchLog($log)) {
                $queued++;
            }
        }

        $remaining = $limit - $queued;
        if ($remaining > 0) {
            $failed = CloudSyncLog::query()
                ->where('status', 'failed')
                ->whereNotNull('payload')
                ->orderBy('created_at')
                ->limit($remaining)
                ->get()
                ->filter(fn (CloudSyncLog $log) => CloudSyncTransport::isTransientMessage($log->last_error));

            foreach ($failed as $log) {
                $log->update(['status' => 'pending', 'last_error' => null]);
                if ($this->dispatchLog($log)) {
                    $queued++;
                }
            }
        }

        if ($queued > 0) {
            Log::info('ris:flush-cloud-sync-pending reencoló registros', ['queued' => $queued]);
            $this->info("Reencolados {$queued} envío(s) a la nube.");
        } else {
            $this->comment('Sin envíos pendientes para reencolar.');
        }

        return self::SUCCESS;
    }

    private function dispatchLog(CloudSyncLog $log): bool
    {
        $payload = app(CloudSyncRetryService::class)->payloadForRetry($log);
        if (!$payload) {
            return false;
        }

        $log->update(['status' => 'pending', 'last_error' => null, 'payload' => $payload]);

        $appointmentId = $payload['id'] ?? $log->entity_id;
        if ($appointmentId && str_contains((string) $log->entity_type, 'Appointment')) {
            SyncAppointmentBundleToCloud::dispatch($appointmentId, $log->action ?? 'updated', $log->id);
        } else {
            SyncEntityToCloud::dispatch(
                $log->entity_type,
                $log->action,
                $payload,
                $log->id
            );
        }

        return true;
    }
}
