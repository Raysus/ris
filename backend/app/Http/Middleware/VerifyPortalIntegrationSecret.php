<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPortalIntegrationSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('portal_integration.enabled', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Integración con portal de pacientes deshabilitada.',
            ], 503);
        }

        $secret = config('portal_integration.secret');
        if (!$secret) {
            return response()->json([
                'success' => false,
                'message' => 'Integración portal no configurada en el servidor (PORTAL_INTEGRATION_SECRET).',
            ], 503);
        }

        $token = $request->bearerToken() ?? $request->header('X-Portal-Integration-Secret');
        if (!is_string($token) || !hash_equals($secret, $token)) {
            return response()->json([
                'success' => false,
                'message' => 'Token de integración portal inválido.',
            ], 401);
        }

        return $next($request);
    }
}
