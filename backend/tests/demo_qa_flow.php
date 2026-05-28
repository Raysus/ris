<?php

/**
 * QA flujo completo API — laboratorios demo (sin SIRESA).
 * Uso: php tests/demo_qa_flow.php [base_url]
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
if (!str_ends_with($base, '/api')) {
    $base .= '/api';
}
$failed = 0;

function req(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $h = array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers);
    $payload = $body !== null ? json_encode($body) : null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($raw !== false) {
            return ['code' => $code, 'json' => json_decode($raw, true), 'raw' => $raw];
        }
        // Windows/local: curl SSL sin CA → fallback stream
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $h) . "\r\n",
            'content' => $payload ?? '',
            'ignore_errors' => true,
            'timeout' => 60,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    preg_match('/\d{3}/', $http_response_header[0] ?? '', $m);

    return ['code' => (int) ($m[0] ?? 0), 'json' => json_decode($raw ?: '', true), 'raw' => $raw ?: ''];
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

$demoPatterns = ['RIS PRO', 'Sucursal Sur', 'Dental Demo', 'Veterinaria Demo'];
$modulePaths = [
    'lab-profile' => '/lab-profile',
    'agenda-catalogs' => '/agenda-catalogs',
    'appointments' => '/appointments',
    'worklist' => '/worklist',
    'machines' => '/machines',
    'radiologist' => '/radiologist/studies',
    'transcription' => '/transcription/appointments',
    'validation' => '/radiologist/validations',
    'entrega' => '/delivery/studies',
    'dashboard' => '/dashboard/metrics',
    'templates' => '/templates',
    'supplies' => '/supplies',
    'settings' => '/settings',
    'users' => '/users',
    'payments' => '/payments/insurance-plans',
];

echo "Demo labs QA (todos los módulos) → $base\n\n";

$login = req('POST', "$base/login", [
    'login_field' => 'rgutierrez',
    'password' => 'rgutierrez',
]);
check('Login rgutierrez', ($login['json']['success'] ?? false) === true, $login['raw']);

$token = $login['json']['access_token'] ?? null;
if (!$token) {
    exit(1);
}

$labsRes = req('GET', "$base/laboratories", null, ["Authorization: Bearer $token"]);
check('GET laboratories', $labsRes['code'] === 200);

$demoLabIds = [];
foreach ($labsRes['json']['data'] ?? [] as $matriz) {
    $name = $matriz['name'] ?? '';
    if (stripos($name, 'Siresa') !== false) {
        continue;
    }
    $isDemo = false;
    foreach ($demoPatterns as $p) {
        if (stripos($name, $p) !== false) {
            $isDemo = true;
            break;
        }
    }
    if (!$isDemo) {
        continue;
    }
    $demoLabIds[] = ['id' => $matriz['id'], 'name' => $name];
    foreach ($matriz['children'] ?? [] as $child) {
        $cn = $child['name'] ?? '';
        if (stripos($cn, 'Siresa') === false) {
            $demoLabIds[] = ['id' => $child['id'], 'name' => $cn];
        }
    }
}

check('Demo labs found', count($demoLabIds) >= 4, 'count=' . count($demoLabIds));

foreach ($demoLabIds as $lab) {
    echo "\n--- {$lab['name']} ---\n";
    $auth = ["Authorization: Bearer $token", "X-Lab-Id: {$lab['id']}"];

    foreach ($modulePaths as $label => $url) {
        $r = req('GET', "$base$url", null, $auth);
        check("{$lab['name']} → $label", $r['code'] === 200, "HTTP {$r['code']}");
    }

    $profile = req('GET', "$base/lab-profile", null, $auth);
    if ($profile['code'] === 200) {
        $code = $profile['json']['data']['code'] ?? '?';
        $wl = ($profile['json']['data']['uses_dicom_worklist'] ?? true) ? 'worklist' : 'atencion';
        echo "       perfil: $code, módulo técnico: $wl\n";
    }
}

$fri = req('POST', "$base/login", ['login_field' => 'friquelme', 'password' => 'friquelme']);
check('Login friquelme', ($fri['json']['success'] ?? false) === true);
$ft = $fri['json']['access_token'] ?? null;
$clinical = null;
foreach ($demoLabIds as $lab) {
    if (stripos($lab['name'], 'RIS PRO') !== false) {
        $clinical = $lab['id'];
        break;
    }
}
if ($ft && $clinical) {
    $r = req('GET', "$base/worklist", null, ["Authorization: Bearer $ft", "X-Lab-Id: $clinical"]);
    check('friquelme worklist RIS PRO', $r['code'] === 200);
}

echo "\n" . ($failed === 0 ? "Demo QA flow: TODO OK\n" : "Demo QA flow: $failed fallo(s)\n");
exit($failed > 0 ? 1 : 0);
