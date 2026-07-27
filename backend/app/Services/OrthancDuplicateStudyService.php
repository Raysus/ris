<?php

namespace App\Services;

use App\Support\OrthancUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Orthanc puede acabar con varios recursos Study que comparten el mismo
 * StudyInstanceUID (p. ej. US y DX de la misma cita). WADO-RS /metadata
 * responde 404 "Multiple Study found" y OHIF no muestra imágenes.
 */
class OrthancDuplicateStudyService
{
    /**
     * @return list<array{study_instance_uid: string, orthanc_ids: list<string>, instance_counts: list<int>}>
     */
    public function findDuplicates(?string $bearerToken = null): array
    {
        $http = $this->http($bearerToken);
        $base = OrthancUrl::base();
        $response = $http->get("{$base}/studies");
        if (!$response->successful()) {
            throw new \RuntimeException('No se pudo listar estudios Orthanc: HTTP ' . $response->status());
        }

        $uidMap = [];
        foreach ($response->json() ?? [] as $studyId) {
            if (!is_string($studyId) || $studyId === '') {
                continue;
            }
            $study = $http->get("{$base}/studies/{$studyId}");
            if (!$study->successful()) {
                continue;
            }
            $uid = (string) ($study->json('MainDicomTags.StudyInstanceUID') ?? '');
            if ($uid === '') {
                continue;
            }
            $uidMap[$uid][] = $studyId;
        }

        $dups = [];
        foreach ($uidMap as $uid => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $counts = [];
            foreach ($ids as $id) {
                $counts[] = $this->instanceCount($base, $id, $http);
            }
            $dups[] = [
                'study_instance_uid' => $uid,
                'orthanc_ids' => array_values($ids),
                'instance_counts' => $counts,
            ];
        }

        return $dups;
    }

    /**
     * Fusiona recursos Study duplicados con el mismo StudyInstanceUID.
     *
     * @return array{merged: int, failed: int, details: list<array<string, mixed>>}
     */
    public function mergeDuplicates(bool $dryRun = false, ?string $bearerToken = null): array
    {
        $http = $this->http($bearerToken);
        $base = OrthancUrl::base();
        $dups = $this->findDuplicates($bearerToken);
        $merged = 0;
        $failed = 0;
        $details = [];

        foreach ($dups as $dup) {
            $uid = $dup['study_instance_uid'];
            $ids = $dup['orthanc_ids'];
            $counts = $dup['instance_counts'];
            $scored = [];
            foreach ($ids as $i => $id) {
                $scored[] = [$counts[$i] ?? 0, $id];
            }
            rsort($scored);
            $target = $scored[0][1];
            $sources = array_values(array_map(fn ($row) => $row[1], array_slice($scored, 1)));

            $entry = [
                'study_instance_uid' => $uid,
                'target' => $target,
                'sources' => $sources,
                'instance_counts' => array_column($scored, 0),
            ];

            if ($dryRun) {
                $entry['status'] = 'dry-run';
                $details[] = $entry;
                $merged++;
                continue;
            }

            $response = $http->timeout(300)->post("{$base}/studies/{$target}/merge", [
                'Resources' => $sources,
                'KeepSource' => false,
            ]);

            if (!$response->successful()) {
                $failed++;
                $entry['status'] = 'failed';
                $entry['error'] = $response->body();
                $details[] = $entry;
                Log::warning('Orthanc merge duplicados falló', $entry);
                continue;
            }

            $merged++;
            $entry['status'] = 'merged';
            $entry['response'] = $response->json();
            $details[] = $entry;
        }

        return compact('merged', 'failed', 'details');
    }

    /**
     * Si hay varios Study con el mismo UID, los fusiona y devuelve el id canónico.
     */
    public function ensureSingleStudyForUid(string $studyInstanceUid, ?string $bearerToken = null): ?string
    {
        $studyInstanceUid = trim($studyInstanceUid);
        if ($studyInstanceUid === '') {
            return null;
        }

        $http = $this->http($bearerToken);
        $base = OrthancUrl::base();
        $ids = $this->findStudyIdsByUid($base, $studyInstanceUid, $http);
        if ($ids === []) {
            return null;
        }
        if (count($ids) === 1) {
            return $ids[0];
        }

        $scored = [];
        foreach ($ids as $id) {
            $scored[] = [$this->instanceCount($base, $id, $http), $id];
        }
        rsort($scored);
        $target = $scored[0][1];
        $sources = array_values(array_map(fn ($row) => $row[1], array_slice($scored, 1)));

        $response = $http->timeout(300)->post("{$base}/studies/{$target}/merge", [
            'Resources' => $sources,
            'KeepSource' => false,
        ]);

        if (!$response->successful()) {
            Log::warning('Orthanc auto-merge por UID falló', [
                'study_instance_uid' => $studyInstanceUid,
                'target' => $target,
                'sources' => $sources,
                'body' => $response->body(),
            ]);

            return $target;
        }

        return $target;
    }

    /**
     * Quita códigos [Fonasa] de StudyDescription / SeriesDescription (modify Orthanc).
     *
     * @return array{modified: int, skipped: int, failed: int}
     */
    public function sanitizeBracketDescriptions(bool $dryRun = false, ?string $bearerToken = null): array
    {
        $http = $this->http($bearerToken);
        $base = OrthancUrl::base();
        $response = $http->get("{$base}/studies");
        if (!$response->successful()) {
            throw new \RuntimeException('No se pudo listar estudios Orthanc: HTTP ' . $response->status());
        }

        $modified = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($response->json() ?? [] as $studyId) {
            if (!is_string($studyId) || $studyId === '') {
                continue;
            }
            $study = $http->get("{$base}/studies/{$studyId}");
            if (!$study->successful()) {
                $failed++;
                continue;
            }
            $desc = (string) ($study->json('MainDicomTags.StudyDescription') ?? '');
            $clean = $this->stripBracketCodes($desc);
            if ($clean === $desc || ($desc !== '' && $clean === '')) {
                // series-level only?
                $seriesTouched = $this->sanitizeSeriesDescriptions($base, $studyId, $http, $dryRun);
                if ($seriesTouched === 0) {
                    $skipped++;
                } else {
                    $modified += $seriesTouched;
                }
                continue;
            }

            if ($dryRun) {
                $modified++;
                continue;
            }

            $mod = $http->timeout(300)->post("{$base}/studies/{$studyId}/modify", [
                'Replace' => ['StudyDescription' => $clean],
                'KeepSource' => false,
                'Force' => true,
            ]);
            if ($mod->successful()) {
                $modified++;
                $this->sanitizeSeriesDescriptions($base, $studyId, $http, false);
            } else {
                $failed++;
                Log::warning('Orthanc sanitize StudyDescription falló', [
                    'study_id' => $studyId,
                    'body' => $mod->body(),
                ]);
            }
        }

        return compact('modified', 'skipped', 'failed');
    }

    private function sanitizeSeriesDescriptions(
        string $base,
        string $studyId,
        $http,
        bool $dryRun
    ): int {
        $study = $http->get("{$base}/studies/{$studyId}");
        if (!$study->successful()) {
            return 0;
        }
        $touched = 0;
        foreach ($study->json('Series') ?? [] as $seriesId) {
            if (!is_string($seriesId)) {
                continue;
            }
            $series = $http->get("{$base}/series/{$seriesId}");
            if (!$series->successful()) {
                continue;
            }
            $desc = (string) ($series->json('MainDicomTags.SeriesDescription') ?? '');
            $pps = (string) ($series->json('MainDicomTags.PerformedProcedureStepDescription') ?? '');
            $replace = [];
            $cleanDesc = $this->stripBracketCodes($desc);
            $cleanPps = $this->stripBracketCodes($pps);
            if ($desc !== '' && $cleanDesc !== '' && $cleanDesc !== $desc) {
                $replace['SeriesDescription'] = $cleanDesc;
            }
            if ($pps !== '' && $cleanPps !== '' && $cleanPps !== $pps) {
                $replace['PerformedProcedureStepDescription'] = $cleanPps;
            }
            if ($replace === []) {
                continue;
            }
            $touched++;
            if ($dryRun) {
                continue;
            }
            $http->timeout(300)->post("{$base}/series/{$seriesId}/modify", [
                'Replace' => $replace,
                'KeepSource' => false,
                'Force' => true,
            ]);
        }

        return $touched;
    }

    public function stripBracketCodes(string $text): string
    {
        $text = preg_replace('/\[[^\]]*\]/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    private function findStudyIdsByUid(string $base, string $uid, $http): array
    {
        $response = $http->post("{$base}/tools/find", [
            'Level' => 'Study',
            'Query' => ['StudyInstanceUID' => $uid],
        ]);
        if (!$response->successful()) {
            return [];
        }
        $ids = $response->json();
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter($ids, fn ($id) => is_string($id) && $id !== ''));
    }

    private function instanceCount(string $base, string $studyId, $http): int
    {
        $response = $http->get("{$base}/studies/{$studyId}/instances");
        if (!$response->successful()) {
            return 0;
        }

        return count($response->json() ?? []);
    }

    private function http(?string $bearerToken)
    {
        $client = Http::timeout(60)->acceptJson();
        $token = trim((string) ($bearerToken ?: config('services.orthanc.http_bearer', '')));
        if ($token !== '') {
            $client = $client->withToken($token);
        }

        return $client;
    }
}
