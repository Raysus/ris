<?php

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrthancStudyLookup
{
    public function orthancBaseUrl(): string
    {
        return \App\Support\OrthancUrl::base();
    }

    /**
     * Obtiene el StudyInstanceUID DICOM asociado a un accession number en Orthanc/PACS.
     */
    public function studyInstanceUidForAccession(string $accessionNumber, ?Appointment $appointment = null): ?string
    {
        $accessionNumber = trim($accessionNumber);
        if ($accessionNumber === '') {
            return null;
        }

        if ($this->looksLikeStudyInstanceUid($accessionNumber)) {
            return $accessionNumber;
        }

        if ($appointment?->study_instance_uid) {
            return $appointment->study_instance_uid;
        }

        $orthancBase = $this->orthancBaseUrl();

        try {
            $uid = $this->studyInstanceUidViaRestFind($orthancBase, $accessionNumber)
                ?? $this->studyInstanceUidViaDicomWeb($orthancBase, $accessionNumber);

            if ($uid === null && $appointment !== null) {
                $uid = $this->studyInstanceUidViaPatientContext($orthancBase, $appointment, $accessionNumber);
            }

            return $uid;
        } catch (\Throwable $e) {
            Log::warning('No se pudo resolver StudyInstanceUID en Orthanc', [
                'accession' => $accessionNumber,
                'orthanc' => $orthancBase,
                'appointment_id' => $appointment?->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function persistStudyInstanceUid(Appointment $appointment, string $studyInstanceUid): void
    {
        $studyInstanceUid = trim($studyInstanceUid);
        if ($studyInstanceUid === '' || $appointment->study_instance_uid === $studyInstanceUid) {
            return;
        }

        $appointment->study_instance_uid = $studyInstanceUid;
        $appointment->save();
    }

    public function looksLikeStudyInstanceUid(string $value): bool
    {
        $value = trim($value);

        return (bool) preg_match('/^[\d.]+$/', $value) && substr_count($value, '.') >= 2;
    }

    private function studyInstanceUidViaRestFind(string $orthancBase, string $accessionNumber): ?string
    {
        foreach ([$accessionNumber, '*' . $accessionNumber . '*'] as $queryAccession) {
            $studyId = $this->findStudyIdWithInstances($orthancBase, $queryAccession);
            if ($studyId === null) {
                continue;
            }

            $uid = $this->studyInstanceUidFromOrthancStudyId($orthancBase, $studyId);
            if ($uid !== null) {
                return $uid;
            }
        }

        return null;
    }

    private function studyInstanceUidViaDicomWeb(string $orthancBase, string $accessionNumber): ?string
    {
        $root = rtrim($orthancBase, '/') . '/dicom-web/studies';

        foreach ([$accessionNumber, '*' . $accessionNumber . '*'] as $queryAccession) {
            $response = Http::timeout(12)
                ->acceptJson()
                ->get($root, [
                    'AccessionNumber' => $queryAccession,
                    'includefield' => '0020000D',
                    'limit' => 5,
                ]);

            if (!$response->successful()) {
                continue;
            }

            $studies = $response->json();
            if (!is_array($studies) || $studies === []) {
                continue;
            }

            foreach ($studies as $study) {
                $uid = $this->dicomTagValue($study, '0020000D');
                if ($uid !== null) {
                    return $uid;
                }
            }
        }

        return null;
    }

    private function studyInstanceUidViaPatientContext(
        string $orthancBase,
        Appointment $appointment,
        string $accessionNumber
    ): ?string {
        $appointment->loadMissing('patient.persona');
        $rut = $appointment->patient?->persona?->rut ?? '';
        $patientId = strtoupper(str_replace(['.', '-', ' '], '', trim($rut)));

        if ($patientId === '') {
            return null;
        }

        $root = rtrim($orthancBase, '/') . '/dicom-web/studies';
        $response = Http::timeout(12)
            ->acceptJson()
            ->get($root, [
                'PatientID' => $patientId,
                'limit' => 25,
            ]);

        if (!$response->successful()) {
            return null;
        }

        $studies = $response->json();
        if (!is_array($studies) || $studies === []) {
            return null;
        }

        $normalizedAccession = $this->normalizeAccession($accessionNumber);

        foreach ($studies as $study) {
            $pacsAccession = $this->dicomTagValue($study, '00080050');
            if ($pacsAccession !== null && $this->normalizeAccession($pacsAccession) === $normalizedAccession) {
                $uid = $this->dicomTagValue($study, '0020000D');
                if ($uid !== null) {
                    return $uid;
                }
            }
        }

        if (count($studies) === 1) {
            return $this->dicomTagValue($studies[0], '0020000D');
        }

        return null;
    }

    private function studyInstanceUidFromOrthancStudyId(string $orthancBase, string $studyId): ?string
    {
        $studyResponse = Http::timeout(10)->get("{$orthancBase}/studies/{$studyId}");

        if (!$studyResponse->successful()) {
            return null;
        }

        $tags = $studyResponse->json('MainDicomTags') ?? [];
        $uid = $tags['StudyInstanceUID'] ?? null;

        return is_string($uid) && $uid !== '' ? $uid : null;
    }

    private function findStudyIdWithInstances(string $orthancBase, string $accessionQuery): ?string
    {
        $response = Http::timeout(10)->post("{$orthancBase}/tools/find", [
            'Level' => 'Study',
            'Query' => [
                'AccessionNumber' => $accessionQuery,
            ],
        ]);

        if (!$response->successful()) {
            Log::debug('Orthanc find por accession falló', [
                'accession' => $accessionQuery,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $studyIds = $response->json();
        if (!is_array($studyIds) || $studyIds === []) {
            return null;
        }

        $bestId = null;
        $bestCount = -1;

        foreach ($studyIds as $studyId) {
            $studyId = (string) $studyId;
            $instances = Http::timeout(5)->get("{$orthancBase}/studies/{$studyId}/instances");
            $count = $instances->successful() ? count($instances->json() ?? []) : 0;

            if ($count > $bestCount) {
                $bestCount = $count;
                $bestId = $studyId;
            }
        }

        return $bestId;
    }

    /** @param array<string, mixed> $study */
    private function dicomTagValue(array $study, string $tag): ?string
    {
        $entry = $study[$tag] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $values = $entry['Value'] ?? null;
        if (!is_array($values) || $values === []) {
            return null;
        }

        $first = $values[0];

        return is_string($first) && $first !== '' ? $first : null;
    }

    private function normalizeAccession(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $value) ?? '');
    }
}
