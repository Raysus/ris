<?php

namespace App\Console\Commands;

use App\Services\OrthancStudySyncService;
use Illuminate\Console\Command;

class SyncOrthancStudyStatus extends Command
{
    protected $signature = 'ris:sync-orthanc-status';

    protected $description = 'Detecta estudios recibidos en Orthanc y actualiza citas dicom_enviado → en_atencion';

    public function handle(OrthancStudySyncService $sync): int
    {
        $updated = $sync->syncPendingAppointments();

        $this->info("Citas actualizadas con imágenes en PACS: {$updated}");

        return self::SUCCESS;
    }
}
