<?php

namespace App\Support;

use App\Models\Laboratory;

class LaboratoryMwlRelay
{
    public static function resolveUrl(?Laboratory $lab): ?string
    {
        if (!$lab) {
            return null;
        }

        $settings = is_array($lab->settings) ? $lab->settings : [];
        $fromSettings = trim((string) ($settings['local_mwl_relay_url'] ?? ''));
        if ($fromSettings !== '') {
            return rtrim($fromSettings, '/');
        }

        $map = config('cloud_sync.lab_mwl_relay_urls', []);
        if (!is_array($map)) {
            return null;
        }

        $fromMap = trim((string) ($map[$lab->id] ?? ''));

        return $fromMap !== '' ? rtrim($fromMap, '/') : null;
    }

    public static function resolveReceiptRelayUrl(?Laboratory $lab): ?string
    {
        $mwlUrl = self::resolveUrl($lab);
        if ($mwlUrl === null) {
            return null;
        }

        if (str_contains($mwlUrl, '/local-mwl/relay')) {
            return str_replace('/local-mwl/relay', '/local-receipt/print', $mwlUrl);
        }

        return rtrim($mwlUrl, '/') . '/local-receipt/print';
    }

    public static function shouldRelayFromCloud(?Laboratory $lab): bool
    {
        return CloudSyncMode::isCloud()
            && !OrthancUrl::usesLocalWorklist()
            && self::resolveUrl($lab) !== null;
    }
}
