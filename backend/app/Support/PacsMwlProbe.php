<?php

namespace App\Support;

use App\Support\ModalityCode;

/**
 * Prueba C-FIND MWL contra el PACS (findscu + dump2dcm nativos o Docker dcmtk).
 *
 * wlmscpfs exige que estación, modalidad y fecha vayan dentro de
 * ScheduledProcedureStepSequence; consultas planas (-k a nivel raíz) matchean
 * todas las worklists y provocan «dataset is empty» / Find SCP Failed.
 */
class PacsMwlProbe
{
    /**
     * @return array{
     *     ok: bool,
     *     pending: bool,
     *     failed: bool,
     *     output: string,
     *     exit_code: int,
     *     calling_ae: string,
     *     station_ae: string,
     *     modality: string,
     *     date: string,
     *     patient_name: ?string
     * }
     */
    public static function run(
        string $stationAe,
        ?string $modality = null,
        ?string $date = null,
        ?string $callingAe = null
    ): array {
        $pacs = OrthancUrl::worklistDicomTarget();
        $host = self::resolveProbeHost($pacs['host']);
        $station = strtoupper(trim($stationAe));
        $modality = strtoupper(trim((string) ($modality ?: ModalityCode::forDicomWorklist('CR', $station))));
        $date = trim((string) ($date ?: date('Ymd')));
        $calling = trim((string) ($callingAe ?: $station));

        $output = [];
        $exitCode = 0;
        $text = self::execFindscu(
            $host,
            (int) $pacs['port'],
            $calling,
            $pacs['aet'],
            $station,
            $modality,
            $date,
            $output,
            $exitCode
        );

        $failed = self::outputIndicatesFailure($text);
        $pending = preg_match('/Find Response:\s*\d+\s*\(Pending\)/', $text) === 1;
        $patientName = null;
        if (preg_match('/\(0010,0010\)\s*PN\s*\[(.*?)\]/', $text, $match) === 1) {
            $patientName = $match[1];
        }

        return [
            'ok' => ! $failed && $pending,
            'pending' => $pending,
            'failed' => $failed,
            'output' => $text,
            'exit_code' => $exitCode,
            'calling_ae' => $calling,
            'station_ae' => $station,
            'modality' => $modality,
            'date' => $date,
            'patient_name' => $patientName,
        ];
    }

    /**
     * Desde contenedores Docker la IP LAN (MWL_DICOM_HOST) puede no enrutar;
     * el servicio compose «mwl» sí alcanza wlmscpfs en la misma red.
     */
    private static function resolveProbeHost(string $configuredHost): string
    {
        if (! OrthancUrl::usesLocalWorklist() || OrthancUrl::worklistProvider() !== 'wlmscpfs') {
            return $configuredHost;
        }

        $internal = trim((string) config('services.mwl.internal_host', 'mwl'));
        if ($internal !== '' && gethostbyname($internal) !== $internal) {
            return $internal;
        }

        return $configuredHost;
    }

    private static function outputIndicatesFailure(string $text): bool
    {
        if ($text === '') {
            return true;
        }

        foreach ([
            'Find Failed',
            'Peer Aborted',
            'Find SCP Failed',
            'DIMSE Failed',
            'dataset is empty',
            'findscu/dump2dcm no disponibles',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $output
     */
    private static function execFindscu(
        string $host,
        int $port,
        string $calling,
        string $aec,
        string $station,
        string $modality,
        string $date,
        array &$output,
        int &$exitCode
    ): string {
        $dump = self::buildQueryDump($station, $modality, $date);

        if (self::hasNativeDcmtk()) {
            $text = self::execFindscuNative($host, $port, $calling, $aec, $dump, $output, $exitCode);
            if ($exitCode === 0 || str_contains($text, 'Find Response') || str_contains($text, 'Find SCP Failed')) {
                return $text;
            }
            $output = [];
        }

        if (self::canUseDocker()) {
            return self::execFindscuDocker($host, $port, $calling, $aec, $dump, $output, $exitCode);
        }

        $exitCode = 127;
        $message = 'findscu/dump2dcm no disponibles (instale dcmtk o monte /var/run/docker.sock para usar darthunix/dcmtk).';
        $output = [$message];

        return $message;
    }

    private static function buildQueryDump(string $station, string $modality, string $date): string
    {
        return implode("\n", [
            '# Dicom-Data-Set',
            '(0040,0100) SQ',
            '  (fffe,e000) na',
            '    (0008,0060) CS [' . $modality . ']',
            '    (0040,0001) AE [' . $station . ']',
            '    (0040,0002) DA [' . $date . ']',
            '  (fffe,e00d) na',
            '  (fffe,e0dd) na',
            '',
        ]);
    }

    /**
     * @param  list<string>  $output
     */
    private static function execFindscuNative(
        string $host,
        int $port,
        string $calling,
        string $aec,
        string $dump,
        array &$output,
        int &$exitCode
    ): string {
        $tmpdir = self::makeTempDir();
        $dumpPath = $tmpdir . '/query.dump';
        $dcmPath = $tmpdir . '/query.dcm';

        try {
            file_put_contents($dumpPath, $dump);
            exec(
                'timeout 15 dump2dcm ' . escapeshellarg($dumpPath) . ' ' . escapeshellarg($dcmPath) . ' 2>&1',
                $dumpOutput,
                $dumpCode
            );
            if ($dumpCode !== 0) {
                $exitCode = $dumpCode;
                $output = $dumpOutput;

                return implode("\n", $dumpOutput);
            }

            $findscuArgs = [
                'timeout', '20', 'findscu', '-W', '-v',
                '-aet', $calling,
                '-aec', $aec,
                $host, (string) $port,
                $dcmPath,
            ];
            $cmd = implode(' ', array_map('escapeshellarg', $findscuArgs));
            exec($cmd . ' 2>&1', $output, $exitCode);
        } finally {
            self::removeTempDir($tmpdir);
        }

        return implode("\n", $output);
    }

    /**
     * @param  list<string>  $output
     */
    private static function execFindscuDocker(
        string $host,
        int $port,
        string $calling,
        string $aec,
        string $dump,
        array &$output,
        int &$exitCode
    ): string {
        $inner = implode("\n", [
            'dump2dcm /dev/stdin /tmp/q.dcm <<\'MWL_EOF\'',
            rtrim($dump, "\n"),
            'MWL_EOF',
            'findscu -W -v'
                . ' -aet ' . escapeshellarg($calling)
                . ' -aec ' . escapeshellarg($aec)
                . ' ' . escapeshellarg($host)
                . ' ' . escapeshellarg((string) $port)
                . ' /tmp/q.dcm 2>&1',
        ]);

        $cmd = 'timeout 25 docker run --rm --network host darthunix/dcmtk sh -c '
            . escapeshellarg($inner);
        exec($cmd . ' 2>&1', $output, $exitCode);

        return implode("\n", $output);
    }

    private static function makeTempDir(): string
    {
        $tmpdir = sys_get_temp_dir() . '/mwl-probe-' . uniqid('', true);
        mkdir($tmpdir, 0700, true);

        return $tmpdir;
    }

    private static function removeTempDir(string $tmpdir): void
    {
        foreach (glob($tmpdir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($tmpdir);
    }

    private static function hasNativeDcmtk(): bool
    {
        return self::commandOnPath('findscu') && self::commandOnPath('dump2dcm');
    }

    private static function canUseDocker(): bool
    {
        return self::commandOnPath('docker') && is_readable('/var/run/docker.sock');
    }

    private static function commandOnPath(string $command): bool
    {
        $which = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'where' : 'command -v';
        exec($which . ' ' . escapeshellarg($command) . ' 2>/dev/null', $out, $code);

        return $code === 0 && $out !== [];
    }
}
