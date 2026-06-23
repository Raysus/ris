<?php

namespace App\Console\Commands;

use App\Services\DemoLaboratoryPurgeService;
use Illuminate\Console\Command;

class PurgeDemoLaboratories extends Command
{
    protected $signature = 'ris:purge-demo-laboratories
                            {--dry-run : Solo listar laboratorios demo que se eliminarían}
                            {--force : Sin confirmación interactiva}';

    protected $description = 'Elimina laboratorios demo y sus datos; conserva solo sedes SIRESA.';

    public function handle(DemoLaboratoryPurgeService $purge): int
    {
        if ($this->option('dry-run')) {
            $stats = $purge->purge(true);
            if ($stats['labs'] === []) {
                $this->info('No hay laboratorios demo en la base de datos.');
                return self::SUCCESS;
            }
            $this->warn('Se eliminarían:');
            foreach ($stats['labs'] as $name) {
                $this->line("  - {$name}");
            }
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('¿Eliminar todos los laboratorios demo y sus datos?')) {
            return self::SUCCESS;
        }

        $stats = $purge->purge(false);

        $this->info('Laboratorios demo eliminados: ' . count($stats['labs']));
        foreach ($stats['labs'] as $name) {
            $this->line("  ✓ {$name}");
        }
        $this->line("Citas eliminadas: {$stats['appointments']} (+ {$stats['demo_accessions']} DEMO-*)");
        $this->line("Pacientes demo eliminados: {$stats['patients']}");

        return self::SUCCESS;
    }
}
