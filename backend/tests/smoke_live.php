<?php

/**
 * Smoke test manual contra API en ejecución (php artisan serve).
 * Uso: php tests/smoke_live.php [base_url]
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$failed = 0;

function req(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $h = array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['code' => $code, 'json' => json_decode($raw, true), 'raw' => $raw];
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failed;
    if ($ok) {
        echo "[OK]   $label\n";
        return;
    }
    $failed++;
    echo "[FAIL] $label" . ($detail ? " — $detail" : '') . "\n";
}

echo "Smoke test live → $base\n\n";

$health = req('GET', "$base/up");
check('Health /up', $health['code'] === 200, "HTTP {$health['code']}");

$login = req('POST', "$base/api/login", [
    'login_field' => 'rgutierrez',
    'password' => 'rgutierrez',
]);
check('Login rgutierrez', $login['code'] === 200 && ($login['json']['success'] ?? false) === true, $login['raw']);

$token = $login['json']['access_token'] ?? null;
$labId = $login['json']['contexto_laboratorio']['laboratorio_id'] ?? null;
check('Token recibido', !empty($token));
check('Lab contexto recibido', !empty($labId));

if (!$token || !$labId) {
    echo "\nAbortando: sin token o laboratorio.\n";
    exit(1);
}

$auth = ["Authorization: Bearer $token", "X-Lab-Id: $labId"];

foreach ([
    'Dashboard' => "$base/api/dashboard/metrics",
    'Agenda catálogos' => "$base/api/agenda-catalogs",
    'Exámenes' => "$base/api/exams",
    'Citas' => "$base/api/appointments",
    'Worklist' => "$base/api/worklist",
    'Usuarios' => "$base/api/users",
    'Radiólogo' => "$base/api/radiologist/studies",
    'Entrega' => "$base/api/delivery/studies",
    'Planes pago' => "$base/api/payments/insurance-plans",
] as $label => $url) {
    $r = req('GET', $url, null, $auth);
    check($label, $r['code'] === 200, "HTTP {$r['code']}");
}

$users = req('GET', "$base/api/users", null, $auth);
$usernames = array_column($users['json']['data'] ?? [], 'username');
check('Sys.admin oculto en /users', !in_array('admin', $usernames, true));
check('rgutierrez visible en /users', in_array('rgutierrez', $usernames, true));

echo "\n" . ($failed === 0 ? "Smoke test live: TODO OK\n" : "Smoke test live: $failed fallo(s)\n");
exit($failed === 0 ? 0 : 1);
