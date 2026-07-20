<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class CloudSyncFilePackager
{
    /** @var list<string> */
    public const ENTITY_FILE_COLUMNS = [
        'medical_order_path',
        'survey_path',
        'signature_path',
        'audio_path',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function packEntityPayload(array &$payload, bool $includeStudyAudio = true): void
    {
        foreach (self::ENTITY_FILE_COLUMNS as $col) {
            if (empty($payload[$col])) {
                continue;
            }
            $base64 = self::fileToBase64((string) $payload[$col]);
            if ($base64) {
                $payload[$col . '_base64'] = $base64;
            }
        }

        if (!$includeStudyAudio || !isset($payload['studies']) || !is_array($payload['studies'])) {
            return;
        }

        foreach ($payload['studies'] as $key => $study) {
            if (!is_array($study)) {
                continue;
            }
            foreach (['audio_path', 'report_document_path'] as $col) {
                if (empty($study[$col])) {
                    continue;
                }
                $base64 = self::fileToBase64((string) $study[$col]);
                if ($base64) {
                    $payload['studies'][$key][$col . '_base64'] = $base64;
                }
            }
        }
    }

    /**
     * Incrusta archivos de cita (orden médica, encuesta, audio de estudios) como base64.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function packAppointmentPayload(array &$payload, bool $includeFiles = true): void
    {
        if (!$includeFiles) {
            return;
        }
        self::packEntityPayload($payload, true);
    }

    /**
     * Quita campos *_base64 del payload (sync liviano de estado/metadatos).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withoutBase64(array $payload): array
    {
        unset($payload['audio_path_base64'], $payload['medical_order_path_base64'], $payload['survey_path_base64'], $payload['signature_path_base64']);

        if (isset($payload['studies']) && is_array($payload['studies'])) {
            foreach ($payload['studies'] as $key => $study) {
                if (!is_array($study)) {
                    continue;
                }
                unset(
                    $payload['studies'][$key]['audio_path_base64'],
                    $payload['studies'][$key]['report_document_path_base64']
                );
            }
        }

        return $payload;
    }

    public static function payloadHasBase64(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && str_ends_with($key, '_base64') && $value) {
                return true;
            }
        }

        if (isset($payload['studies']) && is_array($payload['studies'])) {
            foreach ($payload['studies'] as $study) {
                if (!is_array($study)) {
                    continue;
                }
                foreach ($study as $key => $value) {
                    if (is_string($key) && str_ends_with($key, '_base64') && $value) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function fileToBase64(?string $path): ?string
    {
        $cleanPath = self::normalizeStoragePath($path);
        if ($cleanPath === null || !Storage::disk('public')->exists($cleanPath)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($cleanPath);
        $content = Storage::disk('public')->get($cleanPath);
        $mime = @mime_content_type($absolutePath) ?: 'application/octet-stream';

        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    /**
     * Archivos en documents/ que existen en disco pero no están referenciados por ninguna cita.
     *
     * @return list<array{storage_path: string, modified_at: int|null, base64: string}>
     */
    public static function collectOrphanDocuments(iterable $referencedPaths = []): array
    {
        $referenced = [];
        foreach ($referencedPaths as $path) {
            $normalized = self::normalizeStoragePath(is_string($path) ? $path : null);
            if ($normalized !== null) {
                $referenced[$normalized] = true;
            }
        }

        if (!Storage::disk('public')->exists('documents')) {
            return [];
        }

        $orphans = [];
        foreach (Storage::disk('public')->files('documents') as $relative) {
            if (isset($referenced[$relative])) {
                continue;
            }

            $base64 = self::fileToBase64('/storage/' . $relative);
            if ($base64 === null) {
                continue;
            }

            $orphans[] = [
                'storage_path' => $relative,
                'modified_at' => Storage::disk('public')->lastModified($relative) ?: null,
                'base64' => $base64,
            ];
        }

        usort($orphans, fn (array $a, array $b) => ($a['modified_at'] ?? 0) <=> ($b['modified_at'] ?? 0));

        return $orphans;
    }

    public static function normalizeStoragePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $path = preg_replace('#^https?://[^/]+/storage/#', '', $path) ?? $path;
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return $path !== '' ? $path : null;
    }
}
