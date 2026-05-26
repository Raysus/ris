<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class DicomImportService
{
    public function orthancBaseUrl(): string
    {
        return rtrim(env('ORTHANC_URL', 'http://127.0.0.1:8042'), '/');
    }

    /**
     * Sube .dcm o .zip a Orthanc y etiqueta estudios (PatientID, PatientName, AccessionNumber, InstitutionName).
     *
     * @return array{study_ids: list<string>, instances_count: int, accession: string}
     */
    public function uploadAndTag(
        UploadedFile $file,
        string $patientId,
        string $patientNameDicom,
        string $accessionNumber,
        string $institutionName
    ): array {
        $orthancUrl = $this->orthancBaseUrl();
        $extension = strtolower($file->getClientOriginalExtension());
        $uploadedStudies = [];

        if ($extension === 'zip') {
            $uploadedStudies = $this->uploadZip($file, $orthancUrl);
        } else {
            $uploadedStudies = $this->uploadSingleDicom($file, $orthancUrl);
        }

        if ($uploadedStudies === []) {
            throw new \RuntimeException('No se encontraron instancias DICOM válidas en el archivo.');
        }

        $patientId = strtoupper(str_replace(['.', '-', ' '], '', $patientId));
        $patientNameDicom = strtoupper($patientNameDicom);
        $institutionName = strtoupper(trim($institutionName));

        $uniqueStudies = array_values(array_unique($uploadedStudies));

        foreach ($uniqueStudies as $studyId) {
            $this->modifyStudyTags($studyId, $patientId, $patientNameDicom, $accessionNumber, $institutionName, $orthancUrl);
        }

        return [
            'study_ids' => $uniqueStudies,
            'instances_count' => count($uploadedStudies),
            'accession' => $accessionNumber,
        ];
    }

    public function formatPatientNameDicom(string $names, string $lastName): string
    {
        $n = strtoupper(trim(preg_replace('/\s+/', ' ', $names)));
        $a = strtoupper(trim(preg_replace('/\s+/', ' ', $lastName)));

        if ($a !== '' && $n !== '') {
            return "{$a}^{$n}";
        }

        return strtoupper(trim("{$n} {$a}"));
    }

    /** @return list<string> Orthanc study IDs */
    private function uploadZip(UploadedFile $file, string $orthancUrl): array
    {
        $studies = [];
        $zip = new ZipArchive;

        if ($zip->open($file->getRealPath()) !== true) {
            throw new \RuntimeException('No se pudo abrir el archivo ZIP.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (str_ends_with($filename, '/') || str_contains($filename, '__MACOSX')) {
                continue;
            }

            $stream = $zip->getFromIndex($i);
            if ($stream === false) {
                continue;
            }

            $studyId = $this->postInstance($orthancUrl, $stream);
            if ($studyId) {
                $studies[] = $studyId;
            }
        }

        $zip->close();

        return $studies;
    }

    /** @return list<string> */
    private function uploadSingleDicom(UploadedFile $file, string $orthancUrl): array
    {
        $content = file_get_contents($file->getRealPath());
        $studyId = $this->postInstance($orthancUrl, $content);

        return $studyId ? [$studyId] : [];
    }

    private function postInstance(string $orthancUrl, string $body): ?string
    {
        $response = Http::timeout(120)
            ->withHeaders(['Content-Type' => 'application/dicom'])
            ->send('POST', "{$orthancUrl}/instances", ['body' => $body]);

        if (!$response->successful()) {
            Log::warning('Orthanc rechazó instancia', ['body' => $response->body()]);

            return null;
        }

        $data = $response->json();

        return $data['ParentStudy'] ?? null;
    }

    private function modifyStudyTags(
        string $studyId,
        string $patientId,
        string $patientName,
        string $accessionNumber,
        string $institutionName,
        string $orthancUrl
    ): void {
        $payload = [
            'Replace' => [
                'PatientID' => $patientId,
                'PatientName' => $patientName,
                'AccessionNumber' => $accessionNumber,
                'InstitutionName' => $institutionName,
            ],
            'Force' => true,
            'KeepSource' => false,
        ];

        $response = Http::timeout(60)->post("{$orthancUrl}/studies/{$studyId}/modify", $payload);

        if (!$response->successful()) {
            throw new \RuntimeException("Orthanc no pudo etiquetar el estudio: {$response->body()}");
        }

        $newId = $response->json()['ID'] ?? null;
        if ($newId && $institutionName !== '') {
            $label = strtolower(str_replace(' ', '_', $institutionName));
            Http::put("{$orthancUrl}/studies/{$newId}/labels/{$label}");
        }
    }
}
