<?php

namespace App\Support;

class CloudSyncMode
{
    public static function role(): string
    {
        $role = strtolower((string) config('cloud_sync.role', 'auto'));
        if ($role !== 'auto') {
            return $role;
        }

        if (filled(env('CLOUD_API_BASE')) || filled(env('CLOUD_SERVER_URL'))) {
            return 'local';
        }

        $appUrl = (string) config('app.url', '');
        if (str_contains($appUrl, 'healthticloud.cl')) {
            return 'cloud';
        }

        return 'cloud';
    }

    public static function isCloud(): bool
    {
        return self::role() === 'cloud';
    }

    public static function isLocal(): bool
    {
        return self::role() === 'local';
    }

    public static function acceptsInbound(): bool
    {
        if (!config('cloud_sync.inbound_enabled', true)) {
            return false;
        }

        return self::isCloud() || self::role() === 'auto';
    }

    public static function canPushToCloud(): bool
    {
        return filled(config('cloud_sync.secret')) && filled(config('cloud_sync.inbound_url'));
    }
}
