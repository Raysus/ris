<?php

namespace App\Console\Commands;

use App\Support\ModalityCode;
use App\Support\OrthancUrl;
use App\Support\PacsMwlProbe;
use Illuminate\Console\Command;

class ProbePacsMwl extends Command
{
    protected $signature = 'pacs:probe-mwl
                            {--station= : Scheduled Station AE Title (ej. FCR_PANO)}
                            {--modality= : Modality DICOM (ej. CR). Por defecto según --station}
                            {--date= : Fecha YYYYMMDD. Por defecto hoy}
                            {--calling= : Calling AE Title. Por defecto igual que --station}';

    protected $description = 'Prueba C-FIND worklist contra el PACS DICOM (findscu en PATH o docker darthunix/dcmtk)';

    public function handle(): int
    {
        $pacs = OrthancUrl::usesLocalWorklist()
            ? OrthancUrl::worklistDicomTarget()
            : OrthancUrl::dicomTarget();
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

        $result = PacsMwlProbe::run($station, $modality, $date, $calling);
        $text = $result['output'];
        $this->line($text);

        if ($result['failed']) {
            if (str_contains($text, 'findscu/dump2dcm no disponibles')) {
                $this->error('No hay findscu/dump2dcm en PATH ni docker.sock accesible.');
            } elseif (str_contains($text, 'Find SCP Failed') || str_contains($text, 'dataset is empty')) {
                $this->error('C-FIND falló en el PACS (consulta mal formada o demasiados resultados).');
            } else {
                $this->error('C-FIND rechazado por el PACS (Find Failed).');
            }
            if (strtoupper($calling) !== strtoupper($station)) {
                $this->line("Probó calling «{$calling}» con estación «{$station}»: deben ser iguales (FilterIssuerAet en Orthanc).");
            }
            $this->line("En FCR Console → DICOM Setup → Local AE Title debe ser exactamente «{$station}» (igual que Admin → Salas).");

            return self::FAILURE;
        }

        if ($result['exit_code'] !== 0) {
            $this->error("findscu terminó con código {$result['exit_code']}.");

            return self::FAILURE;
        }

        if ($result['pending']) {
            $patient = $result['patient_name'] ?? null;
            if ($patient !== null && $patient !== '') {
                $this->info("C-FIND devolvió worklist: paciente «{$patient}» (Local AE = «{$station}»).");
            } else {
                $this->info('C-FIND devolvió worklist(s) con estos filtros (el Fuji debería verlas si su Local AE = «' . $station . '»).');
            }

            return self::SUCCESS;
        }

        $this->warn('C-FIND OK pero lista vacía para estación+modalidad+fecha (el Fuji verá lo mismo).');
        $this->line('Compruebe: (1) reenviar worklist desde el RIS, (2) fecha del FCR = «' . $date . '», (3) modalidad CR/MG.');
        $this->line('Si el FCR tiene otro Local AE (ej. FCR), el PACS rechaza la consulta: cámbielo a «' . $station . '» o pida a sistemas desactivar FilterIssuerAet.');

        return self::FAILURE;
    }
}
