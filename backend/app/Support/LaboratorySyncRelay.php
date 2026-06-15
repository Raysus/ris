<?php

namespace App\Support;

use App\Models\Laboratory;

class LaboratorySyncRelay
{
    public static function resolveUrl(?Laboratory $lab): ?string
    {
        if (!$lab) {
            return null;
        }

        $settings = is_array($lab->settings) ? $lab->settings : [];
        $fromSettings = trim((string) ($settings['local_sync_relay_url'] ?? ''));
        if ($fromSettings !== '') {
            return rtrim($fromSettings, '/');
        }

        $map = config('cloud_sync.lab_sync_relay_urls', []);
        if (is_array($map)) {
            $fromMap = trim((string) ($map[$lab->id] ?? ''));
            if ($fromMap !== '') {
                return rtrim($fromMap, '/');
            }
        }

        $mwlUrl = LaboratoryMwlRelay::resolveUrl($lab);
        if ($mwlUrl === null) {
            return null;
        }

        if (str_contains($mwlUrl, '/local-mwl/relay')) {
            return str_replace('/local-mwl/relay', '/local-sync/appointment', $mwlUrl);
        }

        return rtrim($mwlUrl, '/') . '/../local-sync/appointment';
    }

    public static function shouldRelayFromCloud(?Laboratory $lab): bool
    {
        return CloudSyncMode::isCloud() && self::resolveUrl($lab) !== null;
    }
}
