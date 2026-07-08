<?php

namespace App\Console\Commands;

use App\Jobs\SyncAppointmentBundleToCloud;
use App\Models\AppointmentStudy;
use App\Support\CloudSyncMode;
use Illuminate\Console\Command;

class SyncAudiosToCloud extends Command
{
    protected $signature = 'ris:sync-audios-to-cloud
                            {--appointment= : UUID de cita específica}
                            {--sync : Ejecutar de inmediato (sin cola)}';

    protected $description = 'Reenvía a la nube las citas con audio dictado (Siresa → nube)';

    public function handle(): int
    {
        if (!CloudSyncMode::isLocal() || !CloudSyncMode::canPushToCloud()) {
            $this->error('Solo aplica en laboratorio local con CLOUD_API_BASE y CLOUD_SYNC_SECRET.');

            return self::FAILURE;
        }

        $appointmentId = $this->option('appointment');

        $query = AppointmentStudy::query()
            ->whereNotNull('audio_path')
            ->where('audio_path', '!=', '');

        if ($appointmentId) {
            $query->where('appointment_id', $appointmentId);
        }

        $appointmentIds = $query
            ->pluck('appointment_id')
            ->unique()
            ->values();

        if ($appointmentIds->isEmpty()) {
            $this->comment('No hay citas con audio para sincronizar.');

            return self::SUCCESS;
        }

        $this->info('Citas con audio: ' . $appointmentIds->count());

        foreach ($appointmentIds as $id) {
            if ($this->option('sync')) {
                SyncAppointmentBundleToCloud::dispatchSync((string) $id, 'updated');
                $this->line("✓ {$id}");
            } else {
                SyncAppointmentBundleToCloud::dispatch((string) $id, 'updated');
                $this->line("→ cola {$id}");
            }
        }

        $this->info($this->option('sync')
            ? 'Sincronización de audios completada.'
            : 'Audios encolados; el worker los enviará a la nube.');

        return self::SUCCESS;
    }
}
