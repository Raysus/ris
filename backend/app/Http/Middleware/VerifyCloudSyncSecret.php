<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCloudSyncSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('cloud_sync.secret');
        if (!$secret) {
            return response()->json([
                'success' => false,
                'message' => 'Sincronización cloud no configurada en el servidor.',
            ], 503);
        }

        $token = $request->bearerToken() ?? $request->header('X-Cloud-Sync-Secret');
        if (!is_string($token) || !hash_equals($secret, $token)) {
            return response()->json([
                'success' => false,
                'message' => 'Token de sincronización inválido.',
            ], 401);
        }

        return $next($request);
    }
}
