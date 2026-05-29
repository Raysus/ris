<?php

namespace App\Services;

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
    public function studyInstanceUidForAccession(string $accessionNumber): ?string
    {
        $accessionNumber = trim($accessionNumber);
        if ($accessionNumber === '') {
            return null;
        }

        if ($this->looksLikeStudyInstanceUid($accessionNumber)) {
            return $accessionNumber;
        }

        $orthancBase = $this->orthancBaseUrl();

        try {
            $studyId = $this->findStudyIdWithInstances($orthancBase, $accessionNumber);
            if ($studyId === null) {
                $wildcard = '*' . $accessionNumber . '*';
                if ($wildcard !== $accessionNumber) {
                    $studyId = $this->findStudyIdWithInstances($orthancBase, $wildcard);
                }
            }

            if ($studyId === null) {
                return null;
            }

            $studyResponse = Http::timeout(10)->get("{$orthancBase}/studies/{$studyId}");

            if (!$studyResponse->successful()) {
                return null;
            }

            $tags = $studyResponse->json('MainDicomTags') ?? [];
            $uid = $tags['StudyInstanceUID'] ?? null;

            return is_string($uid) && $uid !== '' ? $uid : null;
        } catch (\Throwable $e) {
            Log::warning('No se pudo resolver StudyInstanceUID en Orthanc', [
                'accession' => $accessionNumber,
                'orthanc' => $orthancBase,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function looksLikeStudyInstanceUid(string $value): bool
    {
        $value = trim($value);

        return (bool) preg_match('/^[\d.]+$/', $value) && substr_count($value, '.') >= 2;
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
}
