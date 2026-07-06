<?php

namespace App\Console\Commands;

use App\Services\CloudCatalogPullService;
use App\Support\CloudSyncMode;
use Illuminate\Console\Command;

class PullAgendaFromCloud extends Command
{
    protected $signature = 'ris:pull-agenda-from-cloud
        {--lab= : UUID del laboratorio o matriz (incluye sucursales)}
        {--from= : Fecha inicio YYYY-MM-DD (por defecto hace 30 días)}
        {--to= : Fecha fin YYYY-MM-DD (por defecto +6 meses)}
        {--no-users : No importar usuarios de la sede}';

    protected $description = 'Importa catálogo, pacientes, citas (con documentos escaneados) y exámenes desde la nube al laboratorio local';

    public function handle(CloudCatalogPullService $pull): int
    {
        if (CloudSyncMode::acceptsInbound()) {
            $this->error('Este comando es para laboratorios locales (RIS_CLOUD_ROLE=local).');

            return self::FAILURE;
        }

        if (!CloudSyncMode::canPushToCloud()) {
            $this->error('Configure CLOUD_EXPORT_URL y CLOUD_SYNC_SECRET en .env');

            return self::FAILURE;
        }

        $labId = (string) ($this->option('lab') ?: config('app.current_lab_id') ?: '');
        if ($labId === '') {
            $this->error('Indique --lab=<uuid> del laboratorio.');

            return self::FAILURE;
        }

        $from = $this->option('from') ?: null;
        $to = $this->option('to') ?: null;
        $includeUsers = !$this->option('no-users');

        $this->info("Importando agenda desde la nube (lab={$labId})...");

        try {
            $result = $pull->pull(
                $labId,
                true,
                null,
                $includeUsers,
                true,
                $from,
                $to,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $counts = $result['counts'] ?? [];
        foreach ($counts as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        $imported = (int) ($counts['appointments'] ?? 0);
        $failed = (int) ($counts['appointments_failed'] ?? 0);
        $docs = (int) ($counts['appointment_documents'] ?? 0);
        $orphans = (int) ($counts['orphan_documents'] ?? 0);
        $orphansStored = (int) ($counts['orphan_documents_stored'] ?? 0);
        $this->info("Listo: {$imported} citas importadas" . ($failed > 0 ? ", {$failed} con error (ver log)" : '') . '.');
        if ($docs > 0) {
            $this->line("  citas con documentos embebidos: {$docs}");
        }
        if ($orphans > 0) {
            $this->line("  documentos huérfanos en nube: {$orphans} (copiados localmente: {$orphansStored})");
            $this->warn('  Los huérfanos no se vinculan solos. Use ris:link-appointment-document si conoce la cita.');
        }

        return $failed > 0 && $imported === 0 ? self::FAILURE : self::SUCCESS;
    }
}
