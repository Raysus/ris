<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrthancStudyLookup
{
    public function orthancBaseUrl(): string
    {
        return rtrim(env('ORTHANC_URL', 'http://127.0.0.1:8042'), '/');
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
            $response = Http::timeout(10)->post("{$orthancBase}/tools/find", [
                'Level' => 'Study',
                'Query' => [
                    'AccessionNumber' => $accessionNumber,
                ],
            ]);

            if (!$response->successful()) {
                return null;
            }

            $studyIds = $response->json();
            if (!is_array($studyIds) || $studyIds === []) {
                return null;
            }

            $studyId = (string) $studyIds[0];
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
}
