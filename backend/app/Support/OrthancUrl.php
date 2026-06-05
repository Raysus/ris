<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * URL base HTTP de Orthanc para worklist, importación DICOM y visor.
 * Prioridad: ORTHANC_URL → http(s)://ORTHANC_HOST:8042 → localhost (solo dev).
 */
class OrthancUrl
{
    public static function base(): string
    {
        $url = trim((string) config('services.orthanc.url', ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }

        $host = trim((string) config('services.orthanc.host', ''));
        if ($host !== '') {
            if (str_starts_with($host, 'http://') || str_starts_with($host, 'https://')) {
                return rtrim($host, '/');
            }

            return 'http://' . $host . ':8042';
        }

        return 'http://127.0.0.1:8042';
    }

    /**
     * AE Title DICOM del PACS (called AET para C-FIND/C-ECHO/C-STORE).
     * Prioridad: valor reportado por /system → ORTHANC_AET → HEALTHTICLOUD.
     */
    public static function resolveDicomAet(): string
    {
        return Cache::remember('orthanc.dicom_aet', 300, function (): string {
            try {
                $response = Http::timeout(8)->get(self::base() . '/system');
                if ($response->successful()) {
                    $aet = trim((string) ($response->json('DicomAet') ?? ''));
                    if ($aet !== '') {
                        return $aet;
                    }
                }
            } catch (\Throwable) {
                // PACS HTTP no alcanzable; usar .env
            }

            $configured = trim((string) config('services.orthanc.aet', ''));

            return $configured !== '' ? $configured : 'HEALTHTICLOUD';
        });
    }

    public static function forgetDicomAetCache(): void
    {
        Cache::forget('orthanc.dicom_aet');
    }

    /** Destino DICOM (C-FIND MWL / C-STORE) que debe usar el equipo en sala. */
    public static function dicomTarget(): array
    {
        $parsed = parse_url(self::base());
        $host = trim((string) config('services.orthanc.dicom_host', ''));
        if ($host === '') {
            $host = trim((string) config('services.orthanc.host', ''));
        }
        if ($host === '' && !empty($parsed['host'])) {
            $host = $parsed['host'];
        }

        return [
            'host' => $host,
            'port' => (int) config('services.orthanc.port', 4242),
            'aet' => self::resolveDicomAet(),
            'http_host' => $parsed['host'] ?? $host,
        ];
    }
}
