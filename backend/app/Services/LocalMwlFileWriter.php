<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Escribe entradas MWL como archivos .wl para wlmscpfs (DCMTK, open source).
 *
 * Formato MWL genérico: límites SH/PN aplicados en WorklistTagNormalizer antes de escribir.
 *
 * @see https://support.dcmtk.org/docs/wlmscpfs.html
 */
class LocalMwlFileWriter
{
    public function directory(): string
    {
        $path = trim((string) config('services.mwl.files_path', ''));

        return $path !== '' ? $path : storage_path('app/mwl-worklists');
    }

    /** Subdirectorio wlmscpfs según called AET (ej. /data/SIRESA_MWL/). */
    public function storageAreaDirectory(): string
    {
        $aet = trim((string) config('services.mwl.aet', 'SIRESA_MWL'));
        $safeAet = preg_replace('/[^A-Za-z0-9_-]+/', '_', $aet) ?: 'SIRESA_MWL';
        $dir = $this->directory() . '/' . $safeAet;
        File::ensureDirectoryExists($dir);

        $lockfile = $dir . '/lockfile';
        if (!is_file($lockfile)) {
            touch($lockfile);
        }

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    public function write(array $tags, string $accessionNumber): void
    {
        $dir = $this->storageAreaDirectory();
        $stationAe = $this->resolveStationAe($tags);

        $this->purgeAccessionFile($dir, $stationAe, $accessionNumber);

        $wlPath = $this->wlPath($dir, $stationAe, $accessionNumber);
        $dumpPath = $wlPath . '.dump';

        file_put_contents($dumpPath, $this->buildDump($tags));

        $output = [];
        $exitCode = 0;
        exec('dump2dcm ' . escapeshellarg($dumpPath) . ' ' . escapeshellarg($wlPath) . ' 2>&1', $output, $exitCode);

        @unlink($dumpPath);

        if ($exitCode !== 0 || !is_file($wlPath)) {
            throw new \RuntimeException('dump2dcm falló: ' . implode("\n", $output));
        }

        if (config('services.mwl.fuji_orthanc_alias', false)) {
            $this->writeFujiLegacyStationAlias($tags, $accessionNumber, $stationAe, $dir);
        } else {
            $this->purgeOrthancAliasFiles($dir, $accessionNumber);
        }

        Log::info('MWL local escrita', ['path' => $wlPath, 'accession' => $accessionNumber, 'station' => $stationAe]);
    }

    public function verifyPresent(string $accessionNumber, ?string $stationAe = null): bool
    {
        $dir = $this->storageAreaDirectory();
        if ($stationAe !== null && $stationAe !== '') {
            return is_file($this->wlPath($dir, $stationAe, $accessionNumber));
        }

        $suffix = '__' . $this->safeFilename($accessionNumber) . '.wl';

        foreach (glob($dir . '/*' . $suffix) ?: [] as $file) {
            return true;
        }

        return false;
    }

    private function wlPath(string $dir, string $stationAe, string $accessionNumber): string
    {
        return $dir . '/' . $this->safeFilename($stationAe) . '__' . $this->safeFilename($accessionNumber) . '.wl';
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function resolveStationAe(array $tags): string
    {
        $step = $tags['ScheduledProcedureStepSequence'][0] ?? [];
        $station = trim((string) ($step['ScheduledStationAETitle'] ?? $tags['ScheduledStationAETitle'] ?? ''));

        return $station !== '' ? $station : 'STATION';
    }

    /** Solo reemplaza la misma cita (mismo accession); no borra otros pacientes en la estación. */
    private function purgeAccessionFile(string $dir, string $stationAe, string $accessionNumber): void
    {
        $path = $this->wlPath($dir, $stationAe, $accessionNumber);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function safeFilename(string $accessionNumber): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '_', $accessionNumber) ?: 'worklist';
    }

    private function buildDump(array $tags): string
    {
        $station = $this->resolveStationAe($tags);
        if (str_starts_with(strtoupper($station), 'FCR_')) {
            return $this->buildFujiDump($tags);
        }

        return $this->buildGenericDump($tags);
    }

    /**
     * Perfil MWL Fuji FCR: solo tags que la consola consulta/devuelve.
     * Evita WarningUnsupportedOptionalKeys y error 21054 (falta StudyInstanceUID).
     *
     * @param  array<string, mixed>  $tags
     */
    private function buildFujiDump(array $tags): string
    {
        $steps = $tags['ScheduledProcedureStepSequence'] ?? [];
        if ($steps === []) {
            throw new \InvalidArgumentException('ScheduledProcedureStepSequence vacío para MWL Fuji.');
        }

        $studyUid = trim((string) ($tags['StudyInstanceUID'] ?? ''));
        if ($studyUid === '') {
            throw new \InvalidArgumentException('StudyInstanceUID requerido para MWL Fuji FCR.');
        }

        $requestedProcedureId = trim((string) ($tags['RequestedProcedureID'] ?? $tags['AccessionNumber'] ?? '1'));

        $lines = [
            '# Dicom-File-Format',
            '# Dicom-Data-Set',
            '# Used TransferSyntax: Little Endian Explicit',
            $this->dumpTag('0008,0005', 'CS', (string) ($tags['SpecificCharacterSet'] ?? 'ISO_IR 100')),
            $this->dumpTag('0008,0050', 'SH', (string) ($tags['AccessionNumber'] ?? '')),
            $this->dumpTag('0010,0010', 'PN', (string) ($tags['PatientName'] ?? '')),
            $this->dumpTag('0010,0020', 'LO', (string) ($tags['PatientID'] ?? '')),
        ];

        if (!empty($tags['PatientBirthDate'])) {
            $lines[] = $this->dumpTag('0010,0030', 'DA', (string) $tags['PatientBirthDate']);
        }
        if (!empty($tags['PatientSex'])) {
            $lines[] = $this->dumpTag('0010,0040', 'CS', (string) $tags['PatientSex']);
        }

        $lines[] = $this->dumpTag('0020,000d', 'UI', $studyUid);

        $primaryStep = $steps[0];
        $rootDate = (string) ($primaryStep['ScheduledProcedureStepStartDate'] ?? '');
        $rootTime = (string) ($primaryStep['ScheduledProcedureStepStartTime'] ?? '000000');
        if ($rootDate !== '') {
            $lines[] = $this->dumpTag('0008,0020', 'DA', $rootDate);
        }
        if ($rootTime !== '') {
            $lines[] = $this->dumpTag('0008,0030', 'TM', $rootTime);
        }
        $studyId = substr(preg_replace('/[^A-Za-z0-9_-]+/', '', (string) ($tags['AccessionNumber'] ?? '')) ?: 'STUDY', 0, 16);
        $lines[] = $this->dumpTag('0020,0010', 'SH', $studyId);

        if (!empty($tags['RequestedProcedureDescription'])) {
            $lines[] = $this->dumpTag('0032,1060', 'LO', (string) $tags['RequestedProcedureDescription']);
        }

        $rootModality = (string) ($primaryStep['Modality'] ?? $tags['Modality'] ?? 'CR');
        $rootStation = (string) ($primaryStep['ScheduledStationAETitle'] ?? '');
        // Fuji FCR Broad Query consulta modalidad/estación/fecha a nivel raíz.
        $lines[] = $this->dumpTag('0008,0060', 'CS', $rootModality);
        if ($rootStation !== '') {
            $lines[] = $this->dumpTag('0040,0001', 'AE', $rootStation);
        }
        if ($rootDate !== '') {
            $lines[] = $this->dumpTag('0040,0002', 'DA', $rootDate);
        }

        $lines[] = $this->dumpTag('0040,1001', 'SH', $requestedProcedureId);
        $lines[] = '(0040,0100) SQ';

        foreach ($steps as $step) {
            $procedureDesc = (string) ($step['ScheduledProcedureStepDescription']
                ?? $step['RequestedProcedureDescription']
                ?? $tags['RequestedProcedureDescription']
                ?? 'EXAMEN');

            $lines[] = '  (fffe,e000) na';
            $lines[] = '    ' . $this->dumpTag('0008,0060', 'CS', (string) ($step['Modality'] ?? $tags['Modality'] ?? 'CR'), 4);
            $lines[] = '    ' . $this->dumpTag('0040,0001', 'AE', (string) ($step['ScheduledStationAETitle'] ?? ''), 4);
            $lines[] = '    ' . $this->dumpTag('0040,0002', 'DA', (string) ($step['ScheduledProcedureStepStartDate'] ?? ''), 4);
            $lines[] = '    ' . $this->dumpTag('0040,0003', 'TM', (string) ($step['ScheduledProcedureStepStartTime'] ?? '000000'), 4);
            $lines[] = '    ' . $this->dumpTag('0040,0007', 'LO', $procedureDesc, 4);
            $lines[] = '    ' . $this->dumpTag('0040,0009', 'SH', (string) ($step['ScheduledProcedureStepID'] ?? '1'), 4);
            $lines[] = '  (fffe,e00d) na';
        }

        $lines[] = '  (fffe,e0dd) na';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Formato MWL genérico (wlistdb/OFFIS) para ecógrafos y otras modalidades.
     *
     * @param  array<string, mixed>  $tags
     */
    private function buildGenericDump(array $tags): string
    {
        $steps = $tags['ScheduledProcedureStepSequence'] ?? [];
        if ($steps === []) {
            throw new \InvalidArgumentException('ScheduledProcedureStepSequence vacío para MWL local.');
        }

        $studyUid = trim((string) ($tags['StudyInstanceUID'] ?? ''));
        if ($studyUid === '') {
            $studyUid = '1.2.840.' . time() . '.' . random_int(10000, 99999);
        }

        $requestedProcedureId = trim((string) ($tags['RequestedProcedureID'] ?? $tags['AccessionNumber'] ?? '1'));

        $lines = [
            '# Dicom-File-Format',
            '# Dicom-Data-Set',
            '# Used TransferSyntax: Little Endian Explicit',
            $this->dumpTag('0008,0050', 'SH', (string) ($tags['AccessionNumber'] ?? '')),
            $this->dumpTag('0008,0005', 'CS', (string) ($tags['SpecificCharacterSet'] ?? 'ISO_IR 100')),
            $this->dumpTag('0008,0090', 'PN', (string) ($tags['ReferringPhysicianName'] ?? '')),
            $this->dumpTag('0010,0010', 'PN', (string) ($tags['PatientName'] ?? '')),
            $this->dumpTag('0010,0020', 'LO', (string) ($tags['PatientID'] ?? '')),
        ];

        if (!empty($tags['PatientBirthDate'])) {
            $lines[] = $this->dumpTag('0010,0030', 'DA', (string) $tags['PatientBirthDate']);
        }
        if (!empty($tags['PatientSex'])) {
            $lines[] = $this->dumpTag('0010,0040', 'CS', (string) $tags['PatientSex']);
        }

        $lines[] = $this->dumpTag('0020,000d', 'UI', $studyUid);

        $primaryStep = $steps[0];
        $rootModality = (string) ($tags['Modality'] ?? $primaryStep['Modality'] ?? 'US');
        $rootStation = (string) ($tags['ScheduledStationAETitle'] ?? $primaryStep['ScheduledStationAETitle'] ?? '');
        $rootDate = (string) ($tags['ScheduledProcedureStepStartDate'] ?? $primaryStep['ScheduledProcedureStepStartDate'] ?? '');
        $rootTime = (string) ($tags['ScheduledProcedureStepStartTime'] ?? $primaryStep['ScheduledProcedureStepStartTime'] ?? '');

        if ($rootDate !== '') {
            $lines[] = $this->dumpTag('0008,0020', 'DA', $rootDate);
        }
        if ($rootTime !== '') {
            $lines[] = $this->dumpTag('0008,0030', 'TM', $rootTime);
        }
        $lines[] = $this->dumpTag('0008,0060', 'CS', $rootModality);
        if ($rootStation !== '') {
            $lines[] = $this->dumpTag('0040,0001', 'AE', $rootStation);
        }
        if ($rootDate !== '') {
            $lines[] = $this->dumpTag('0040,0002', 'DA', $rootDate);
        }

        if (!empty($tags['RequestedProcedureDescription'])) {
            $lines[] = $this->dumpTag('0032,1060', 'LO', (string) $tags['RequestedProcedureDescription']);
        }

        foreach ($this->procedureCodeSequenceLines($tags) as $line) {
            $lines[] = $line;
        }

        $lines[] = $this->dumpTag('0032,1032', 'PN', (string) ($tags['RequestingPhysician'] ?? ''));
        $lines[] = $this->dumpTag('0032,1033', 'LO', (string) ($tags['RequestingService'] ?? ''));
        $lines[] = $this->dumpTag('0038,0010', 'LO', (string) ($tags['AdmissionID'] ?? ''));
        $lines[] = $this->dumpTag('0038,0300', 'LO', (string) ($tags['CurrentPatientLocation'] ?? ''));
        $lines[] = $this->dumpTag('0038,0500', 'LO', (string) ($tags['PatientState'] ?? ''));
        $lines[] = $this->dumpTag('0040,1001', 'SH', $requestedProcedureId);
        $lines[] = $this->dumpTag('0040,1003', 'SH', (string) ($tags['RequestedProcedurePriority'] ?? ''));
        $lines[] = $this->dumpTag('0040,1010', 'PN', (string) ($tags['NamesOfIntendedRecipientsOfResults'] ?? ''));
        $lines[] = $this->dumpTag('0040,1400', 'LT', (string) ($tags['RequestedProcedureComments'] ?? ''));

        $lines[] = '(0040,0100) SQ';

        foreach ($steps as $step) {
            $procedureDesc = (string) ($step['ScheduledProcedureStepDescription']
                ?? $step['RequestedProcedureDescription']
                ?? $tags['RequestedProcedureDescription']
                ?? 'EXAMEN');

            $lines[] = '  (fffe,e000) na';
            $lines[] = '    ' . $this->dumpTag('0008,0060', 'CS', (string) ($step['Modality'] ?? $tags['Modality'] ?? 'CR'), 4);
            $lines[] = '    ' . $this->dumpTag('0040,0001', 'AE', (string) ($step['ScheduledStationAETitle'] ?? ''), 4);
            $lines[] = '    ' . $this->dumpTag('0040,0002', 'DA', (string) ($step['ScheduledProcedureStepStartDate'] ?? ''), 4);
            $lines[] = '    ' . $this->dumpTag('0040,0003', 'TM', (string) ($step['ScheduledProcedureStepStartTime'] ?? '000000'), 4);
            $lines[] = '    ' . $this->dumpTag('0040,0006', 'PN', (string) ($step['ScheduledPerformingPhysicianName'] ?? ''), 4);
            $lines[] = '    ' . $this->dumpTag('0040,0007', 'LO', $procedureDesc, 4);
            $lines[] = '    ' . $this->dumpTag('0040,0009', 'SH', (string) ($step['ScheduledProcedureStepID'] ?? '1'), 4);
            $lines[] = '    ' . $this->dumpTag('0040,0010', 'SH', (string) (
                $step['ScheduledStationName'] ?? $step['ScheduledStationAETitle'] ?? ''
            ), 4);
            $lines[] = '    ' . $this->dumpTag('0040,0020', 'CS', (string) ($step['ScheduledProcedureStepStatus'] ?? 'SCHEDULED'), 4);

            foreach ($this->protocolCodeSequenceLines($step) as $line) {
                $lines[] = $line;
            }

            $lines[] = '  (fffe,e00d) na';
        }

        $lines[] = '  (fffe,e0dd) na';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param  array<string, mixed>  $step
     * @return list<string>
     */
    private function protocolCodeSequenceLines(array $step): array
    {
        $items = $step['ScheduledProtocolCodeSequence'] ?? [[
            'CodeValue' => '',
            'CodingSchemeDesignator' => '',
            'CodingSchemeVersion' => '',
            'CodeMeaning' => '',
        ]];

        if (!is_array($items) || $items === []) {
            $items = [['CodeValue' => '', 'CodingSchemeDesignator' => '', 'CodingSchemeVersion' => '', 'CodeMeaning' => '']];
        }

        $lines = ['    (0040,0008) SQ'];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lines[] = '      (fffe,e000) na';
            $lines[] = '        ' . $this->dumpTag('0008,0100', 'SH', (string) ($item['CodeValue'] ?? ''), 8);
            $lines[] = '        ' . $this->dumpTag('0008,0102', 'SH', (string) ($item['CodingSchemeDesignator'] ?? ''), 8);
            $lines[] = '        ' . $this->dumpTag('0008,0103', 'SH', (string) ($item['CodingSchemeVersion'] ?? ''), 8);
            $lines[] = '        ' . $this->dumpTag('0008,0104', 'LO', (string) ($item['CodeMeaning'] ?? ''), 8);
            $lines[] = '      (fffe,e00d) na';
        }

        $lines[] = '      (fffe,e0dd) na';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $tags
     * @return list<string>
     */
    private function procedureCodeSequenceLines(array $tags): array
    {
        $items = $tags['RequestedProcedureCodeSequence'] ?? [[
            'CodeValue' => '',
            'CodingSchemeDesignator' => '',
            'CodingSchemeVersion' => '',
            'CodeMeaning' => '',
        ]];

        if (!is_array($items) || $items === []) {
            $items = [['CodeValue' => '', 'CodingSchemeDesignator' => '', 'CodingSchemeVersion' => '', 'CodeMeaning' => '']];
        }

        $lines = ['(0032,1064) SQ'];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lines[] = '  (fffe,e000) na';
            $lines[] = '    ' . $this->dumpTag('0008,0100', 'SH', (string) ($item['CodeValue'] ?? ''), 4);
            $lines[] = '    ' . $this->dumpTag('0008,0102', 'SH', (string) ($item['CodingSchemeDesignator'] ?? ''), 4);
            $lines[] = '    ' . $this->dumpTag('0008,0103', 'SH', (string) ($item['CodingSchemeVersion'] ?? ''), 4);
            $lines[] = '    ' . $this->dumpTag('0008,0104', 'LO', (string) ($item['CodeMeaning'] ?? ''), 4);
            $lines[] = '  (fffe,e00d) na';
        }

        $lines[] = '  (fffe,e0dd) na';

        return $lines;
    }

    /**
     * Fuji FCR Console a veces consulta con estación legacy «ORTHANC» (PACS antiguo).
     * Duplicamos la entrada con ese AE para que matchee sin cambiar la sala real (FCR_PANO).
     *
     * @param  array<string, mixed>  $tags
     */
    private function writeFujiLegacyStationAlias(
        array $tags,
        string $accessionNumber,
        string $stationAe,
        string $dir
    ): void {
        if (!str_starts_with(strtoupper($stationAe), 'FCR_')) {
            return;
        }

        $legacyAe = 'ORTHANC';
        $aliasTags = $tags;
        $steps = $aliasTags['ScheduledProcedureStepSequence'] ?? [];
        if ($steps === []) {
            return;
        }

        $steps[0]['ScheduledStationAETitle'] = $legacyAe;
        $steps[0]['ScheduledStationName'] = $legacyAe;
        $aliasTags['ScheduledProcedureStepSequence'] = $steps;

        $this->purgeAccessionFile($dir, $legacyAe, $accessionNumber);

        $wlPath = $this->wlPath($dir, $legacyAe, $accessionNumber);
        $dumpPath = $wlPath . '.dump';
        file_put_contents($dumpPath, $this->buildFujiDump($aliasTags));

        $output = [];
        $exitCode = 0;
        exec('dump2dcm ' . escapeshellarg($dumpPath) . ' ' . escapeshellarg($wlPath) . ' 2>&1', $output, $exitCode);
        @unlink($dumpPath);

        if ($exitCode !== 0 || !is_file($wlPath)) {
            Log::warning('No se pudo escribir alias MWL ORTHANC para Fuji', [
                'accession' => $accessionNumber,
                'output' => implode("\n", $output),
            ]);
        }
    }

    /** Elimina alias ORTHANC legacy que contaminan broad query del FCR (devuelven MG/US antes que CR). */
    private function purgeOrthancAliasFiles(string $dir, string $accessionNumber): void
    {
        $suffix = '__' . $this->safeFilename($accessionNumber) . '.wl';
        $path = $dir . '/ORTHANC' . $suffix;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function dumpTag(string $tag, string $vr, string $value, int $indent = 0): string
    {
        $pad = str_repeat(' ', $indent);
        // dump2dcm delimita con [ ]: escapar backslash y eliminar corchetes residuales
        $escaped = str_replace(['\\', '[', ']'], ['\\\\', '(', ')'], trim($value));

        return $pad . '(' . $tag . ') ' . $vr . ' [' . $escaped . ']';
    }
}
