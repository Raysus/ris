<?php

namespace App\Support;

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

    /** Destino DICOM (C-FIND MWL / C-STORE) que debe usar el equipo en sala. */
    public static function dicomTarget(): array
    {
        $parsed = parse_url(self::base());
        $host = trim((string) config('services.orthanc.host', ''));
        if (!empty($parsed['host'])) {
            $host = $parsed['host'];
        }

        return [
            'host' => $host,
            'port' => (int) config('services.orthanc.port', 4242),
            'aet' => (string) config('services.orthanc.aet', 'HealthTICloud'),
        ];
    }
}
