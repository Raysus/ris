<?php

namespace App\Console\Commands;

use App\Services\OrthancDuplicateStudyService;
use Illuminate\Console\Command;

class MergeOrthancDuplicateStudies extends Command
{
    protected $signature = 'ris:orthanc-merge-duplicates
                            {--dry-run : Solo listar duplicados sin fusionar}
                            {--sanitize-descriptions : Quitar códigos [Fonasa] de Study/SeriesDescription}';

    protected $description = 'Fusiona estudios Orthanc con el mismo StudyInstanceUID (arregla OHIF US/DX en blanco)';

    public function handle(OrthancDuplicateStudyService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'Buscando duplicados (dry-run)…' : 'Fusionando duplicados Orthanc…');

        try {
            $result = $service->mergeDuplicates($dryRun);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['details'] as $row) {
            $this->line(sprintf(
                '  %s → target=%s sources=%s [%s]',
                $row['study_instance_uid'],
                $row['target'],
                implode(',', $row['sources'] ?? []),
                $row['status'] ?? '?'
            ));
        }

        $this->info("Fusionados/planificados: {$result['merged']}; fallidos: {$result['failed']}");

        if ($this->option('sanitize-descriptions')) {
            $this->info($dryRun ? 'Sanitizando descripciones (dry-run)…' : 'Sanitizando descripciones…');
            try {
                $san = $service->sanitizeBracketDescriptions($dryRun);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("Descripciones: modified={$san['modified']} skipped={$san['skipped']} failed={$san['failed']}");
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
