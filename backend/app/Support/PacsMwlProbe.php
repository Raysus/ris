<?php

namespace App\Support;

use App\Support\ModalityCode;

/**
 * Prueba C-FIND MWL contra el PACS (findscu nativo o Docker dcmtk).
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

        $findscuArgs = [
            'findscu', '-W', '-v',
            '-aet', $calling,
            '-aec', $pacs['aet'],
            $pacs['host'], (string) $pacs['port'],
            '-k', 'ScheduledStationAETitle=' . $station,
            '-k', 'Modality=' . $modality,
            '-k', 'ScheduledProcedureStepStartDate=' . $date,
        ];

        $output = [];
        $exitCode = 0;
        $text = self::execFindscu($findscuArgs, $output, $exitCode);

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

    /**
     * @param  list<string>  $findscuArgs
     */
    private static function execFindscu(array $findscuArgs, array &$output, int &$exitCode): string
    {
        if (self::findscuOnPath()) {
            $cmd = implode(' ', array_map('escapeshellarg', $findscuArgs));
            exec($cmd . ' 2>&1', $output, $exitCode);
            $text = implode("\n", $output);
            if ($exitCode === 0 || str_contains($text, 'Find Response')) {
                return $text;
            }
            $output = [];
        }

        $dockerArgs = array_merge(
            ['docker', 'run', '--rm', '--network', 'host', 'darthunix/dcmtk'],
            $findscuArgs
        );
        $cmd = implode(' ', array_map('escapeshellarg', $dockerArgs));
        exec($cmd . ' 2>&1', $output, $exitCode);

        return implode("\n", $output);
    }

    private static function findscuOnPath(): bool
    {
        $which = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'where' : 'command -v';
        exec($which . ' findscu 2>/dev/null', $out, $code);

        return $code === 0 && $out !== [];
    }
}
