<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class RisHttp
{
    /**
     * Cliente HTTP con verificación TLS en producción; sin verificar solo en local/testing.
     */
    public static function client(int $timeout = 30): PendingRequest
    {
        $pending = Http::timeout($timeout);

        if (app()->environment('local', 'testing')) {
            return $pending->withoutVerifying();
        }

        return $pending;
    }
}
