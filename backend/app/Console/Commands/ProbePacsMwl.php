<?php

namespace App\Console\Commands;

use App\Support\ModalityCode;
use App\Support\OrthancUrl;
use Illuminate\Console\Command;

class ProbePacsMwl extends Command
{
    protected $signature = 'pacs:probe-mwl
                            {--station= : Scheduled Station AE Title (ej. FCR_PANO)}
                            {--modality= : Modality DICOM (ej. CR). Por defecto según --station}
                            {--date= : Fecha YYYYMMDD. Por defecto hoy}
                            {--calling= : Calling AE Title. Por defecto igual que --station}';

    protected $description = 'Prueba C-FIND worklist contra el PACS DICOM (requiere docker + imagen darthunix/dcmtk en el host)';

    public function handle(): int
    {
        $pacs = OrthancUrl::dicomTarget();
        $station = strtoupper(trim((string) $this->option('station')));
        if ($station === '') {
            $this->error('Indique --station= (ej. FCR_PANO).');

            return self::FAILURE;
        }

        $modality = strtoupper(trim((string) $this->option('modality')));
        if ($modality === '') {
            $modality = ModalityCode::forDicomWorklist('CR', $station);
        }

        $date = trim((string) $this->option('date'));
        if ($date === '') {
            $date = date('Ymd');
        }

        $calling = trim((string) $this->option('calling'));
        if ($calling === '') {
            $calling = $station;
        }

        $this->line("PACS: {$pacs['host']}:{$pacs['port']}  called AET: {$pacs['aet']}");
        $this->line("C-FIND: station={$station} modality={$modality} date={$date} calling={$calling}");

        $args = [
            'docker', 'run', '--rm', '--network', 'host', 'darthunix/dcmtk',
            'findscu', '-W', '-v',
            '-aet', $calling,
            '-aec', $pacs['aet'],
            $pacs['host'], (string) $pacs['port'],
            '-k', 'ScheduledStationAETitle=' . $station,
            '-k', 'Modality=' . $modality,
            '-k', 'ScheduledProcedureStepStartDate=' . $date,
        ];

        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $this->line('Ejecutando: ' . $cmd);

        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);
        $text = implode("\n", $output);
        $this->line($text);

        if (str_contains($text, 'Find Failed') || str_contains($text, 'Peer Aborted')) {
            $this->error('C-FIND falló en el PACS (TCP puede estar OK). Revise Orthanc worklists + C-FIND.');

            return self::FAILURE;
        }

        if ($exitCode !== 0) {
            $this->error("findscu terminó con código {$exitCode}.");

            return self::FAILURE;
        }

        $hasPending = preg_match('/Find Response:\s*\d+\s*\(Pending\)/', $text) === 1;
        if ($hasPending) {
            $this->info('C-FIND devolvió worklist(s) con estos filtros (el Fuji debería verlas).');

            return self::SUCCESS;
        }

        $this->warn('C-FIND OK pero lista vacía para estación+modalidad+fecha (el Fuji verá lo mismo).');
        $this->line('Compruebe: (1) reenviar worklist desde el RIS tras actualizar, (2) fecha del FCR = fecha de la cita, (3) modalidad CR/MG.');
        $this->line('Diagnóstico Orthanc: si por accession sí hay Pending pero este query no, faltan tags planos en la worklist.');

        return self::FAILURE;
    }
}
