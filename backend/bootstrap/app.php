<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $trustedProxies = env('TRUSTED_PROXIES', '');
        $at = match (true) {
            $trustedProxies === '*' => '*',
            $trustedProxies === '' => [],
            default => array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
        };
        $middleware->trustProxies(at: $at);

        $middleware->api(prepend: [
            \App\Http\Middleware\ForceApiCors::class,
        ]);

        $middleware->alias([
            'tenant' => \App\Http\Middleware\CheckLabTenant::class,
            'cloud.sync' => \App\Http\Middleware\VerifyCloudSyncSecret::class,
            'portal.integration' => \App\Http\Middleware\VerifyPortalIntegrationSecret::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'No autenticado.'
                ], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            $errors = $e->errors();
            $first = collect($errors)->flatten()->first() ?? 'Datos inválidos.';
            if (is_string($first) && str_starts_with($first, 'validation.')) {
                $first = match ($first) {
                    'validation.min.string' => 'Uno de los campos de texto es demasiado corto.',
                    'validation.required' => 'Faltan campos obligatorios.',
                    default => 'Datos inválidos. Revise el formulario.',
                };
            }

            return response()->json([
                'success' => false,
                'message' => $first,
                'errors' => $errors,
            ], 422);
        });
    })->create();
