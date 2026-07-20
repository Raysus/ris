<?php
/**
 * Re-sincroniza roles/atributos portal en Keycloak para personal Siresa.
 * Uso (en servidor nube):
 *   cd /var/www/ris.healthticloud.cl/backend && php /ruta/resync-siresa-staff-keycloak.php
 *
 * Incluye secretarias/secretarios activos de laboratorio Siresa.
 */

require __DIR__ . '/../../backend/vendor/autoload.php';
$app = require __DIR__ . '/../../backend/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$extra = array_slice($argv, 1);
$defaultUsernames = ['asandoval', 'mparedes', 'vespinoza', 'rgutierrez', 'cmoreno'];
$usernames = $extra !== [] ? $extra : $defaultUsernames;
$kc = app(\App\Services\KeycloakService::class);

foreach ($usernames as $username) {
    $user = \App\Models\User::with(['persona', 'tipoUsuario', 'laboratories'])
        ->where('username', $username)
        ->first();

    if (!$user || !$user->persona) {
        echo "SKIP {$username}: no encontrado\n";
        continue;
    }

    $risRoles = is_array($user->settings) ? ($user->settings['roles'] ?? []) : [];
    if ($risRoles === [] && $user->tipoUsuario?->name) {
        $risRoles = [$user->tipoUsuario->name];
    }

    $labName = strtoupper((string) ($user->laboratories->first()?->name ?? ''));
    $portalLabId = null;
    $portalSiteFilter = null;
    if (str_contains($labName, 'SIRESA')) {
        $portalLabId = 5;
        $portalSiteFilter = 'SIRESA';
    } elseif (str_contains($labName, 'ECOTEMUCO') || str_contains($labName, 'TEMUCO')) {
        $portalLabId = 6;
        $portalSiteFilter = 'IMEX TEMUCO';
    }

    $mapped = \App\Services\KeycloakService::mapRisRolesToKeycloak($risRoles);
    $keycloakRole = $mapped[0] ?? 'admin';

    try {
        $kc->updateUser(
            $user->persona->rut,
            null,
            $user->persona->email ?? null,
            $keycloakRole,
            [
                'firstName' => $user->persona->names,
                'lastName' => trim(
                    ($user->persona->last_name_1 ?? '') . ' ' . ($user->persona->last_name_2 ?? '')
                ),
                'legacyUsername' => $user->username,
                'risRoles' => $risRoles,
                'portalLabId' => $portalLabId,
                'portalSiteFilter' => $portalSiteFilter,
            ]
        );
        echo "OK {$username} ({$user->persona->rut}) roles=" . implode(',', $mapped)
            . " lab={$portalLabId} site={$portalSiteFilter}\n";
    } catch (\Throwable $e) {
        echo "ERR {$username}: {$e->getMessage()}\n";
    }
}
