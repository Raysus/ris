<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garantiza cabeceras CORS en todas las respuestas API (incl. 401/404/500).
 * Sin esto, Chrome muestra "blocked by CORS" aunque el fallo sea timeout o error PHP.
 */
class ForceApiCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->applyCorsHeaders($request, response('', 204));
        }

        return $this->applyCorsHeaders($request, $next($request));
    }

    private function applyCorsHeaders(Request $request, Response $response): Response
    {
        $origin = $request->headers->get('Origin');
        if (!$origin || !$this->isAllowedOrigin($origin)) {
            return $response;
        }

        if (!$response->headers->has('Access-Control-Allow-Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Content-Type, Authorization, X-Lab-Id, X-Requested-With, Accept, X-Portal-Integration-Secret'
        );
        $response->headers->set('Vary', 'Origin', false);

        return $response;
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $allowed = config('cors.allowed_origins', []);
        if (in_array($origin, $allowed, true)) {
            return true;
        }

        foreach (config('cors.allowed_origins_patterns', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, $origin) === 1) {
                return true;
            }
        }

        $frontend = rtrim((string) env('FRONTEND_URL', ''), '/');
        if ($frontend !== '' && $origin === $frontend) {
            return true;
        }

        return false;
    }
}
