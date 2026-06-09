<?php

namespace App\Console\Commands;

use App\Services\CatalogDedupeService;
use Illuminate\Console\Command;

class DedupeCatalog extends Command
{
    protected $signature = 'catalog:dedupe {--dry-run : Solo mostrar qué se eliminaría}';

    protected $description = 'Elimina duplicados de catálogo (médicos referidos, previsiones, exámenes, etc.)';

    public function handle(CatalogDedupeService $dedupe): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Modo dry-run: no se aplicarán cambios.');
        }

        $results = $dedupe->dedupeAll($dryRun);

        foreach ($results as $entity => $stats) {
            $this->line(sprintf(
                '%s: %d grupos duplicados, %d eliminados, %d citas reasignadas',
                $entity,
                $stats['groups'],
                $stats['removed'],
                $stats['reassigned']
            ));
        }

        $this->info($dryRun ? 'Simulación completada.' : 'Catálogo deduplicado.');

        return self::SUCCESS;
    }
}
