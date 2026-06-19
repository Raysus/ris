<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CloudSyncTransport
{
    public static function defaultTimeout(): int
    {
        return (int) config('cloud_sync.http_timeout', 15);
    }

    public static function releaseDelaySeconds(int $attempts): int
    {
        $base = (int) config('cloud_sync.pending_release_seconds', 60);
        $max = (int) config('cloud_sync.max_release_backoff', 900);

        return min($max, max($base, $base * max(1, $attempts)));
    }

    public static function isTransientHttpStatus(?int $status): bool
    {
        if ($status === null) {
            return true;
        }

        return in_array($status, [408, 429, 500, 502, 503, 504], true);
    }

    public static function isTransient(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            return self::isTransientHttpStatus($e->response?->status());
        }

        return self::isTransientMessage($e->getMessage());
    }

    public static function isTransientMessage(?string $message): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        $msg = strtolower($message);
        foreach ([
            'connection refused',
            'connection timed out',
            'timed out',
            'timeout',
            'could not resolve host',
            'network is unreachable',
            'failed to connect',
            'curl error 6',
            'curl error 7',
            'curl error 28',
            'curl error 35',
            'ssl connection',
            'operation timed out',
            'no route to host',
            'name or service not known',
            'temporarily unavailable',
            'nube no alcanzable',
        ] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function isPermanentHttpStatus(?int $status): bool
    {
        if ($status === null) {
            return false;
        }

        return in_array($status, [400, 401, 403, 404, 409, 422], true);
    }

    /**
     * Comprueba si la nube responde (health o inbound con token).
     */
    public static function cloudReachable(): bool
    {
        if (!CloudSyncMode::canPushToCloud()) {
            return false;
        }

        $secret = config('cloud_sync.secret');
        $candidates = array_values(array_filter([
            config('cloud_sync.health_url'),
            self::healthUrlFromInbound(),
        ]));

        $http = Http::timeout(5);
        if (app()->environment('local', 'testing')) {
            $http = $http->withoutVerifying();
        }

        foreach ($candidates as $url) {
            try {
                $response = $http->withToken($secret)->acceptJson()->get($url);
                if ($response->successful() || $response->status() === 401) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    public static function exceptionFromResponse(Response $response, string $context): \RuntimeException
    {
        return new \RuntimeException(
            sprintf('%s: HTTP %s — %s', $context, $response->status(), $response->body())
        );
    }

    private static function healthUrlFromInbound(): ?string
    {
        $inbound = (string) config('cloud_sync.inbound_url', '');
        if ($inbound === '') {
            return null;
        }

        $base = preg_replace('#/integrations/cloud-sync/inbound$#', '', $inbound);
        if (!$base) {
            return null;
        }

        return rtrim($base, '/') . '/health';
    }
}
