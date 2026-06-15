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
        Cache::forget('mwl.dicom_aet');
    }

    public static function usesLocalWorklist(): bool
    {
        return in_array(self::worklistProvider(), ['orthanc', 'wlmscpfs'], true);
    }

    public static function worklistProvider(): string
    {
        $provider = strtolower(trim((string) config('services.mwl.provider', 'cloud')));
        if ($provider === 'cloud' && trim((string) config('services.mwl.url', '')) !== '') {
            return 'orthanc';
        }

        return $provider;
    }

    /** HTTP para crear/borrar worklists (local MWL o PACS nube si no hay MWL local). */
    public static function worklistBase(): string
    {
        if (self::worklistProvider() === 'wlmscpfs' || self::orthancUsesFiles()) {
            throw new \LogicException('MWL local por archivos no usa HTTP; escriba archivos .wl.');
        }

        $mwlUrl = trim((string) config('services.mwl.url', ''));
        if ($mwlUrl !== '') {
            return rtrim($mwlUrl, '/');
        }

        return self::base();
    }

    /** Orthanc local con plugin legacy ModalityWorklists (archivos .wl), no REST /worklists/create. */
    public static function orthancUsesFiles(): bool
    {
        if (self::worklistProvider() !== 'orthanc') {
            return false;
        }

        return strtolower(trim((string) config('services.mwl.orthanc_mode', 'files'))) === 'files';
    }

    public static function resolveWorklistDicomAet(): string
    {
        if (!self::usesLocalWorklist()) {
            return self::resolveDicomAet();
        }

        if (self::worklistProvider() === 'wlmscpfs' || self::orthancUsesFiles()) {
            $configured = trim((string) config('services.mwl.aet', ''));

            return $configured !== '' ? $configured : 'SIRESA_MWL';
        }

        return Cache::remember('mwl.dicom_aet', 300, function (): string {
            try {
                $response = Http::timeout(8)->get(self::worklistBase() . '/system');
                if ($response->successful()) {
                    $aet = trim((string) ($response->json('DicomAet') ?? ''));
                    if ($aet !== '') {
                        return $aet;
                    }
                }
            } catch (\Throwable) {
                // MWL local no alcanzable; usar .env
            }

            $configured = trim((string) config('services.mwl.aet', ''));

            return $configured !== '' ? $configured : 'SIRESA_MWL';
        });
    }

    /** Destino DICOM MWL (C-FIND worklist) para equipos en sala. */
    public static function worklistDicomTarget(): array
    {
        if (!self::usesLocalWorklist()) {
            return self::dicomTarget();
        }

        if (self::worklistProvider() === 'wlmscpfs' || self::orthancUsesFiles()) {
            return [
                'host' => trim((string) config('services.mwl.dicom_host', '')),
                'port' => (int) config('services.mwl.port', 4242),
                'aet' => self::resolveWorklistDicomAet(),
                'http_host' => trim((string) (parse_url((string) config('services.mwl.url', ''), PHP_URL_HOST) ?: '')) ?: null,
                'local' => true,
            ];
        }

        $parsed = parse_url(self::worklistBase());
        $host = trim((string) config('services.mwl.dicom_host', ''));
        if ($host === '' && !empty($parsed['host'])) {
            $host = $parsed['host'];
        }

        return [
            'host' => $host,
            'port' => (int) config('services.mwl.port', 4242),
            'aet' => self::resolveWorklistDicomAet(),
            'http_host' => $parsed['host'] ?? $host,
            'local' => true,
        ];
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
