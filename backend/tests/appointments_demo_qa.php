<?php

/**
 * QA GET /appointments en los 6 laboratorios demo (sin SIRESA).
 * Uso: php tests/appointments_demo_qa.php [base_url]
 * Ejemplo: php tests/appointments_demo_qa.php https://api.healthticloud.cl
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
if (!str_ends_with($base, '/api')) {
    $base .= '/api';
}

$demoPatterns = ['RIS PRO', 'Sucursal Sur', 'Dental Demo', 'Veterinaria Demo'];
$failed = 0;

function http(string $method, string $url, ?array $body = null, array $extraHeaders = []): array
{
    $headers = array_merge(['Accept: application/json', 'Content-Type: application/json'], $extraHeaders);
    $payload = $body !== null ? json_encode($body) : null;
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $payload ?? '',
            'ignore_errors' => true,
            'timeout' => 90,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    preg_match('/\d{3}/', $http_response_header[0] ?? '0', $m);

    return ['code' => (int) ($m[0] ?? 0), 'json' => json_decode($raw ?: '', true), 'raw' => $raw ?: ''];
}

echo "QA appointments (6 labs demo) → $base\n\n";

$login = http('POST', "$base/login", ['login_field' => 'rgutierrez', 'password' => 'rgutierrez']);
$token = $login['json']['access_token'] ?? null;
if (!$token) {
    echo "[FAIL] Login\n";
    exit(1);
}
echo "[OK]   Login rgutierrez\n";

$labsRes = http('GET', "$base/laboratories", null, ["Authorization: Bearer $token"]);
$demoLabs = [];
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
    $demoLabs[] = ['id' => $matriz['id'], 'name' => $name];
    foreach ($matriz['children'] ?? [] as $child) {
        $cn = $child['name'] ?? '';
        if (stripos($cn, 'Siresa') === false) {
            $demoLabs[] = ['id' => $child['id'], 'name' => $cn];
        }
    }
}

if (count($demoLabs) < 6) {
    echo "[WARN] Se encontraron " . count($demoLabs) . " labs demo (esperados 6)\n";
}

foreach ($demoLabs as $lab) {
    $r = http('GET', "$base/appointments", null, [
        "Authorization: Bearer $token",
        "X-Lab-Id: {$lab['id']}",
    ]);
    $ok = $r['code'] === 200 && ($r['json']['success'] ?? false) === true;
    $count = $ok && is_array($r['json']['data']) ? count($r['json']['data']) : 0;
    $demoCount = 0;
    if ($ok) {
        foreach ($r['json']['data'] as $a) {
            if (str_starts_with((string) ($a['accession_number'] ?? ''), 'DEMO-')) {
                $demoCount++;
            }
        }
    }
    if ($ok) {
        echo "[OK]   {$lab['name']} — citas={$count}, DEMO={$demoCount}\n";
    } else {
        $failed++;
        $msg = $r['json']['message'] ?? substr(preg_replace('/\s+/', ' ', $r['raw']), 0, 180);
        echo "[FAIL] {$lab['name']} — HTTP {$r['code']} — {$msg}\n";
    }
}

echo "\n" . ($failed === 0 ? "Appointments QA: 6/6 OK\n" : "Appointments QA: " . (count($demoLabs) - $failed) . "/" . count($demoLabs) . " OK, {$failed} fallo(s)\n");
exit($failed > 0 ? 1 : 0);
