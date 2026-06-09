<?php

namespace App\Services;

/**
 * Normaliza tags MWL a un formato genérico compatible con equipos heterogéneos:
 * Fuji FCR (CR/MG), ecógrafos (Sonoscape/Mindray US), rayos DX, etc.
 *
 * wlmscpfs exige que ciertos tags presentes (aunque vacíos) en la consulta C-FIND
 * existan también en el .wl — p. ej. ReferringPhysicianName y ScheduledPerformingPhysicianName.
 */
class WorklistTagNormalizer
{
    /**
     * @param  list<array<string, mixed>>  $procedureSteps
     * @return array<string, mixed>
     */
    public function normalize(array $tags, array $procedureSteps, string $accessionNumber): array
    {
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
        $tags['RequestedProcedureID'] = (string) ($tags['RequestedProcedureID'] ?? $accessionNumber);
        $tags['RequestedProcedureCodeSequence'] = $this->emptyProcedureCodeSequence(
            $tags['RequestedProcedureCodeSequence'] ?? null
        );

        $defaultDesc = trim((string) ($tags['RequestedProcedureDescription'] ?? ''));

        $tags['ScheduledProcedureStepSequence'] = array_map(
            fn (array $step): array => $this->normalizeStep($step, $defaultDesc),
            $procedureSteps
        );

        return $tags;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function normalizeStep(array $step, string $defaultDesc): array
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
            16
        );
        $step['ScheduledProcedureStepStatus'] = (string) ($step['ScheduledProcedureStepStatus'] ?? 'SCHEDULED');
        $step['ScheduledPerformingPhysicianName'] = (string) ($step['ScheduledPerformingPhysicianName'] ?? '');
        $step['ScheduledProcedureStepDescription'] = $desc !== '' ? strtoupper($desc) : 'EXAMEN';
        $step['ScheduledProtocolCodeSequence'] = $this->emptyProtocolCodeSequence(
            $step['ScheduledProtocolCodeSequence'] ?? null
        );

        return $step;
    }

    /**
     * Secuencia vacía estándar; algunos ecógrafos la incluyen en C-FIND y esperan eco en la respuesta.
     *
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

    private function truncate(string $value, int $maxLength): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? '' : substr($trimmed, 0, $maxLength);
    }
}
