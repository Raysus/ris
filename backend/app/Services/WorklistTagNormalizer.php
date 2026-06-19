<?php

namespace App\Services;

/**
 * Normaliza tags MWL para equipos DICOM heterogéneos (Fuji FCR, ecógrafos, CT, etc.).
 *
 * Límites genéricos (DICOM SH / bases legacy de consolas):
 * - AccessionNumber, RequestedProcedureID, ScheduledProcedureStepID: ≤16 alfanuméricos
 * - PatientID: ≤16 sin espacios
 * - PatientName: componentes PN acotados (~26 chars en pantalla)
 * - Descripciones de procedimiento: ≤16
 *
 * wlmscpfs: modalidad/estación/fecha solo en ScheduledProcedureStepSequence.
 * Orthanc/PACS: tags raíz adicionales para Broad Query / Refresh.
 */
class WorklistTagNormalizer
{
    public const SH_MAX = 16;

    public const PN_FAMILY_MAX = 17;

    public const PN_GIVEN_MAX = 8;

    /**
     * @param  list<array<string, mixed>>  $procedureSteps
     * @return array<string, mixed>
     */
    public function normalize(
        array $tags,
        array $procedureSteps,
        string $accessionNumber,
        string $mwlProvider = 'cloud',
        bool $orthancUsesFiles = false,
    ): array {
        if ($procedureSteps === []) {
            return $tags;
        }

        $tags['ReferringPhysicianName'] = (string) ($tags['ReferringPhysicianName'] ?? '');
        $tags['RequestingPhysician'] = (string) ($tags['RequestingPhysician'] ?? '');
        $tags['RequestingService'] = (string) ($tags['RequestingService'] ?? '');
        $tags['AdmissionID'] = (string) ($tags['AdmissionID'] ?? '');
        $tags['CurrentPatientLocation'] = (string) ($tags['CurrentPatientLocation'] ?? '');
        $tags['PatientState'] = (string) ($tags['PatientState'] ?? '');
        $tags['RequestedProcedurePriority'] = (string) ($tags['RequestedProcedurePriority'] ?? '');
        $tags['NamesOfIntendedRecipientsOfResults'] = (string) ($tags['NamesOfIntendedRecipientsOfResults'] ?? '');
        $tags['RequestedProcedureComments'] = (string) ($tags['RequestedProcedureComments'] ?? '');

        $mwlAccession = self::compactAccession($accessionNumber);
        $tags['AccessionNumber'] = $mwlAccession;
        $tags['RequestedProcedureID'] = $mwlAccession;
        $tags['PatientID'] = $this->truncate(trim((string) ($tags['PatientID'] ?? '')), self::SH_MAX);
        $tags['PatientName'] = $this->truncatePatientName((string) ($tags['PatientName'] ?? ''));

        if (!empty($tags['RequestedProcedureDescription'])) {
            $tags['RequestedProcedureDescription'] = $this->truncate(
                (string) $tags['RequestedProcedureDescription'],
                self::SH_MAX
            );
        }

        if (!empty($tags['InstitutionName'])) {
            $tags['InstitutionName'] = $this->truncate((string) $tags['InstitutionName'], 64);
        }

        $defaultDesc = trim((string) ($tags['RequestedProcedureDescription'] ?? ''));

        $tags['ScheduledProcedureStepSequence'] = array_map(
            fn (array $step): array => $this->normalizeStep($step, $defaultDesc, $accessionNumber),
            $procedureSteps
        );

        if ($mwlProvider === 'wlmscpfs') {
            $tags['RequestedProcedureCodeSequence'] = $this->emptyProcedureCodeSequence(null);
            $tags['ScheduledProcedureStepSequence'] = array_map(
                fn (array $step): array => $step + [
                    'ScheduledProtocolCodeSequence' => $this->emptyProtocolCodeSequence(null),
                ],
                $tags['ScheduledProcedureStepSequence']
            );
        }

        if ($mwlProvider !== 'wlmscpfs') {
            $tags = $this->applyBroadQueryRootTags($tags, $procedureSteps);
        }

        if ($mwlProvider === 'orthanc' && !$orthancUsesFiles) {
            unset($tags['StudyInstanceUID']);
        }

        return $tags;
    }

    public static function compactAccession(string $accessionNumber, string $fallback = 'WORKLIST'): string
    {
        $compact = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $accessionNumber) ?? '');

        if ($compact === '') {
            $compact = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $fallback) ?: 'WORKLIST');
        }

        if (strlen($compact) <= self::SH_MAX) {
            return $compact;
        }

        // ACC-YYYYMMDD-UUID: truncar al inicio colisiona citas del mismo día; conservar sufijo único.
        return substr($compact, -self::SH_MAX);
    }

    public static function compactStepId(string $accessionNumber, string $fallbackId): string
    {
        return self::compactAccession($accessionNumber, $fallbackId);
    }

    /**
     * @param  list<array<string, mixed>>  $procedureSteps
     * @return array<string, mixed>
     */
    private function applyBroadQueryRootTags(array $tags, array $procedureSteps): array
    {
        $primary = $procedureSteps[0];
        $stationAe = trim((string) ($primary['ScheduledStationAETitle'] ?? ''));
        $modality = (string) ($primary['Modality'] ?? 'OT');
        $stepDate = (string) ($primary['ScheduledProcedureStepStartDate'] ?? '');
        $stepTime = (string) ($primary['ScheduledProcedureStepStartTime'] ?? '');

        $tags['Modality'] = $modality;
        if ($stationAe !== '') {
            $tags['ScheduledStationAETitle'] = $stationAe;
        }
        if ($stepDate !== '') {
            $tags['StudyDate'] = $stepDate;
            $tags['ScheduledProcedureStepStartDate'] = $stepDate;
        }
        if ($stepTime !== '') {
            $tags['StudyTime'] = $stepTime;
            $tags['ScheduledProcedureStepStartTime'] = $stepTime;
        }

        return $tags;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function normalizeStep(array $step, string $defaultDesc, string $accessionNumber): array
    {
        $station = trim((string) ($step['ScheduledStationAETitle'] ?? ''));
        $desc = trim((string) (
            $step['ScheduledProcedureStepDescription']
            ?? $step['RequestedProcedureDescription']
            ?? $defaultDesc
        ));

        $step['ScheduledStationAETitle'] = $station;
        $step['ScheduledStationName'] = $this->truncate(
            trim((string) ($step['ScheduledStationName'] ?? '')) !== ''
                ? (string) $step['ScheduledStationName']
                : ($station !== '' ? $station : 'STATION'),
            self::SH_MAX
        );
        $step['ScheduledProcedureStepStatus'] = (string) ($step['ScheduledProcedureStepStatus'] ?? 'SCHEDULED');
        $step['ScheduledPerformingPhysicianName'] = (string) ($step['ScheduledPerformingPhysicianName'] ?? '');
        $step['ScheduledProcedureStepDescription'] = $this->truncate(
            $desc !== '' ? strtoupper($desc) : 'EXAMEN',
            self::SH_MAX
        );
        $step['ScheduledProcedureStepID'] = self::compactStepId(
            $accessionNumber,
            (string) ($step['ScheduledProcedureStepID'] ?? '1')
        );
        $step['Modality'] = (string) ($step['Modality'] ?? 'OT');

        return $step;
    }

    private function truncatePatientName(string $patientName): string
    {
        $parts = explode('^', $patientName, 3);
        $family = $this->truncate(trim($parts[0] ?? ''), self::PN_FAMILY_MAX);
        $given = $this->truncate(trim($parts[1] ?? ''), self::PN_GIVEN_MAX);

        if ($given === '') {
            return $family;
        }

        return $family . '^' . $given;
    }

    private function truncate(string $value, int $maxLength): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? '' : substr($trimmed, 0, $maxLength);
    }

    /**
     * @return list<array<string, string>>
     */
    private function emptyProtocolCodeSequence(mixed $existing): array
    {
        if (is_array($existing) && $existing !== []) {
            return $existing;
        }

        return [[
            'CodeValue' => '',
            'CodingSchemeDesignator' => '',
            'CodingSchemeVersion' => '',
            'CodeMeaning' => '',
        ]];
    }

    /**
     * @return list<array<string, string>>
     */
    private function emptyProcedureCodeSequence(mixed $existing): array
    {
        return $this->emptyProtocolCodeSequence($existing);
    }
}
