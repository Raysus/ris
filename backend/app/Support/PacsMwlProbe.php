<?php

namespace App\Support;

use App\Support\ModalityCode;

/**
 * Prueba C-FIND MWL contra el PACS (findscu en Docker).
 */
class PacsMwlProbe
{
    /**
     * @return array{ok: bool, pending: bool, failed: bool, output: string, exit_code: int}
     */
    public static function run(
        string $stationAe,
        ?string $modality = null,
        ?string $date = null,
        ?string $callingAe = null
    ): array {
        $pacs = OrthancUrl::dicomTarget();
        $station = strtoupper(trim($stationAe));
        $modality = strtoupper(trim((string) ($modality ?: ModalityCode::forDicomWorklist('CR', $station))));
        $date = trim((string) ($date ?: date('Ymd')));
        $calling = trim((string) ($callingAe ?: $station));

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
        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);
        $text = implode("\n", $output);

        $failed = str_contains($text, 'Find Failed') || str_contains($text, 'Peer Aborted');
        $pending = preg_match('/Find Response:\s*\d+\s*\(Pending\)/', $text) === 1;

        return [
            'ok' => !$failed && $pending,
            'pending' => $pending,
            'failed' => $failed,
            'output' => $text,
            'exit_code' => $exitCode,
            'calling_ae' => $calling,
            'station_ae' => $station,
            'modality' => $modality,
            'date' => $date,
        ];
    }
}
