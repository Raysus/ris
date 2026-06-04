<?php

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrthancStudyLookup
{
    public function orthancBaseUrl(): string
    {
        return \App\Support\OrthancUrl::base();
    }

    /**
     * Resuelve estudio en Orthanc por Accession Number y devuelve metadatos para el visor OHIF.
     *
     * @return array<string, mixed>|null
     */
    public function resolveStudyByAccession(
        string $accessionNumber,
        ?Appointment $appointment = null,
        ?string $bearerToken = null
    ): ?array {
        $accessionNumber = trim($accessionNumber);
        if ($accessionNumber === '') {
            return null;
        }

        if ($appointment?->study_instance_uid && $this->looksLikeStudyInstanceUid($appointment->study_instance_uid)) {
            $enriched = $this->enrichFromOrthancStudyId(
                $this->orthancBaseUrl(),
                null,
                $appointment->study_instance_uid,
                $accessionNumber,
                $bearerToken
            );
            if ($enriched !== null) {
                return $enriched;
            }
        }

        if ($this->looksLikeStudyInstanceUid($accessionNumber)) {
            return $this->enrichFromOrthancStudyId(
                $this->orthancBaseUrl(),
                null,
                $accessionNumber,
                $accessionNumber,
                $bearerToken
            );
        }

        $orthancBase = $this->orthancBaseUrl();
        $http = $this->httpClient($bearerToken);

        try {
            $orthancStudyId = null;
            $studyInstanceUid = null;

            foreach ($this->accessionSearchTerms($accessionNumber) as $term) {
                $orthancStudyId = $this->findStudyIdWithInstances($orthancBase, $term, $http);
                if ($orthancStudyId !== null) {
                    break;
                }
            }

            if ($orthancStudyId !== null) {
                $studyInstanceUid = $this->studyInstanceUidFromOrthancStudyId($orthancBase, $orthancStudyId, $http);
            }

            if ($studyInstanceUid === null) {
                foreach ($this->accessionSearchTerms($accessionNumber) as $term) {
                    $studyInstanceUid = $this->studyInstanceUidViaDicomWeb($orthancBase, $term, $http);
                    if ($studyInstanceUid !== null) {
                        $orthancStudyId = $this->findStudyIdWithInstances($orthancBase, $term, $http);
                        break;
                    }
                }
            }

            if ($studyInstanceUid === null && $appointment !== null) {
                $studyInstanceUid = $this->studyInstanceUidViaPatientContext(
                    $orthancBase,
                    $appointment,
                    $accessionNumber,
                    $http
                );
                if ($studyInstanceUid !== null) {
                    $orthancStudyId = $this->findStudyIdWithInstances($orthancBase, $accessionNumber, $http)
                        ?? $this->findStudyIdWithInstances($orthancBase, '*' . $accessionNumber . '*', $http);
                }
            }

            if ($studyInstanceUid === null || !$this->looksLikeStudyInstanceUid($studyInstanceUid)) {
                return null;
            }

            return $this->enrichFromOrthancStudyId(
                $orthancBase,
                $orthancStudyId,
                $studyInstanceUid,
                $accessionNumber,
                $bearerToken
            );
        } catch (\Throwable $e) {
            Log::warning('No se pudo resolver estudio en Orthanc', [
                'accession' => $accessionNumber,
                'orthanc' => $orthancBase,
                'appointment_id' => $appointment?->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function studyInstanceUidForAccession(
        string $accessionNumber,
        ?Appointment $appointment = null,
        ?string $bearerToken = null
    ): ?string {
        $resolved = $this->resolveStudyByAccession($accessionNumber, $appointment, $bearerToken);

        return is_array($resolved) ? ($resolved['study_instance_uid'] ?? null) : null;
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

        if ($value === '' || $this->looksLikeAccessionNumber($value)) {
            return false;
        }

        return (bool) preg_match('/^[\d.]+$/', $value) && substr_count($value, '.') >= 2;
    }

    public function looksLikeAccessionNumber(string $value): bool
    {
        $value = trim($value);

        return (bool) preg_match('/^ACC-/i', $value) || (preg_match('/[A-Za-z]/', $value) && !$this->looksLikeStudyInstanceUid($value));
    }

    private function httpClient(?string $bearerToken = null): PendingRequest
    {
        $client = Http::timeout(15)->acceptJson();

        $token = trim((string) ($bearerToken ?: config('services.orthanc.http_bearer', '')));
        if ($token !== '') {
            $client = $client->withToken($token);
        }

        return $client;
    }

    /** @return list<string> */
    private function accessionSearchTerms(string $accessionNumber): array
    {
        $terms = [$accessionNumber];
        $wildcard = '*' . $accessionNumber . '*';
        if ($wildcard !== $accessionNumber) {
            $terms[] = $wildcard;
        }

        return $terms;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function enrichFromOrthancStudyId(
        string $orthancBase,
        ?string $orthancStudyId,
        string $studyInstanceUid,
        string $requestedAccession,
        ?string $bearerToken
    ): ?array {
        $http = $this->httpClient($bearerToken);

        if ($orthancStudyId === null) {
            foreach ($this->accessionSearchTerms($requestedAccession) as $term) {
                $orthancStudyId = $this->findStudyIdWithInstances($orthancBase, $term, $http);
                if ($orthancStudyId !== null) {
                    break;
                }
            }
        }

        $tags = [];
        $series = [];
        $patientTags = [];
        $instanceCount = 0;

        if ($orthancStudyId !== null) {
            $studyResponse = $http->get("{$orthancBase}/studies/{$orthancStudyId}");
            if ($studyResponse->successful()) {
                $body = $studyResponse->json();
                $tags = $body['MainDicomTags'] ?? [];
                $series = $body['Series'] ?? [];
                $patientTags = $body['PatientMainDicomTags'] ?? [];
                $instances = $http->get("{$orthancBase}/studies/{$orthancStudyId}/instances");
                $instanceCount = $instances->successful() ? count($instances->json() ?? []) : 0;
                $uidFromTags = $tags['StudyInstanceUID'] ?? null;
                if (is_string($uidFromTags) && $uidFromTags !== '') {
                    $studyInstanceUid = $uidFromTags;
                }
            }
        }

        if (!$this->looksLikeStudyInstanceUid($studyInstanceUid)) {
            return null;
        }

        return [
            'study_instance_uid' => $studyInstanceUid,
            'orthanc_study_id' => $orthancStudyId,
            'accession_number' => $tags['AccessionNumber'] ?? $requestedAccession,
            'accession_requested' => $requestedAccession,
            'patient_id' => $tags['PatientID'] ?? ($patientTags['PatientID'] ?? null),
            'patient_name' => $tags['PatientName'] ?? ($patientTags['PatientName'] ?? null),
            'study_date' => $tags['StudyDate'] ?? null,
            'study_time' => $tags['StudyTime'] ?? null,
            'study_description' => $tags['StudyDescription'] ?? null,
            'modalities_in_study' => $tags['ModalitiesInStudy'] ?? null,
            'series_count' => is_array($series) ? count($series) : 0,
            'instance_count' => $instanceCount,
            'pacs_has_images' => $instanceCount > 0,
        ];
    }

    private function studyInstanceUidViaDicomWeb(string $orthancBase, string $accessionNumber, PendingRequest $http): ?string
    {
        $root = rtrim($orthancBase, '/') . '/dicom-web/studies';

        $response = $http->get($root, [
            'AccessionNumber' => $accessionNumber,
            'includefield' => '0020000D',
            'includefield' => '00080050',
            'limit' => 10,
        ]);

        if (!$response->successful()) {
            return null;
        }

        $studies = $response->json();
        if (!is_array($studies) || $studies === []) {
            return null;
        }

        foreach ($studies as $study) {
            $uid = $this->dicomTagValue($study, '0020000D');
            if ($uid !== null) {
                return $uid;
            }
        }

        return null;
    }

    private function studyInstanceUidViaPatientContext(
        string $orthancBase,
        Appointment $appointment,
        string $accessionNumber,
        PendingRequest $http
    ): ?string {
        $appointment->loadMissing('patient.persona');
        $rut = $appointment->patient?->persona?->rut ?? '';
        $patientId = strtoupper(str_replace(['.', '-', ' '], '', trim($rut)));

        if ($patientId === '') {
            return null;
        }

        $root = rtrim($orthancBase, '/') . '/dicom-web/studies';
        $response = $http->get($root, [
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

        return null;
    }

    private function studyInstanceUidFromOrthancStudyId(
        string $orthancBase,
        string $studyId,
        PendingRequest $http
    ): ?string {
        $studyResponse = $http->get("{$orthancBase}/studies/{$studyId}");

        if (!$studyResponse->successful()) {
            return null;
        }

        $tags = $studyResponse->json('MainDicomTags') ?? [];
        $uid = $tags['StudyInstanceUID'] ?? null;

        return is_string($uid) && $uid !== '' ? $uid : null;
    }

    private function findStudyIdWithInstances(
        string $orthancBase,
        string $accessionQuery,
        PendingRequest $http
    ): ?string {
        $response = $http->post("{$orthancBase}/tools/find", [
            'Level' => 'Study',
            'Query' => [
                'AccessionNumber' => $accessionQuery,
            ],
        ]);

        if (!$response->successful()) {
            Log::debug('Orthanc /tools/find por accession falló', [
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
            $instances = $http->get("{$orthancBase}/studies/{$studyId}/instances");
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
