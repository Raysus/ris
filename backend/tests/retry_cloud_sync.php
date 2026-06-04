<?php

/** Reintenta sync fallidos vía API admin. Uso: php tests/retry_cloud_sync.php [base_url] */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
if (!str_ends_with($base, '/api')) {
    $base .= '/api';
}

function http(string $method, string $url, ?array $body = null, array $extraHeaders = []): array
{
    $headers = array_merge(['Accept: application/json', 'Content-Type: application/json'], $extraHeaders);
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body !== null ? json_encode($body) : '',
            'ignore_errors' => true,
            'timeout' => 60,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    preg_match('/\d{3}/', $http_response_header[0] ?? '0', $m);

    return ['code' => (int) ($m[0] ?? 0), 'json' => json_decode($raw ?: '', true), 'raw' => $raw ?: ''];
}

$login = http('POST', "$base/login", ['login_field' => 'rgutierrez', 'password' => 'rgutierrez']);
$token = $login['json']['access_token'] ?? null;
if (!$token) {
    fwrite(STDERR, "Login falló\n");
    exit(1);
}

$appointmentId = $argv[2] ?? null;

if ($appointmentId) {
    $logs = http('GET', "$base/integrations/cloud-sync?limit=50", null, ["Authorization: Bearer $token"]);
    $logId = null;
    foreach ($logs['json']['data']['logs'] ?? [] as $log) {
        if (($log['entity_id'] ?? '') === $appointmentId) {
            $logId = $log['id'];
            break;
        }
    }
    if (!$logId) {
        fwrite(STDERR, "No hay log para cita {$appointmentId}\n");
        exit(1);
    }
    $r = http('POST', "$base/integrations/cloud-sync/{$logId}/retry", [], ["Authorization: Bearer $token"]);
} else {
    $r = http('POST', "$base/integrations/cloud-sync/retry-failed", [], ["Authorization: Bearer $token"]);
}

echo "HTTP {$r['code']} " . ($r['json']['message'] ?? $r['raw']) . "\n";
exit($r['code'] === 200 && ($r['json']['success'] ?? false) ? 0 : 1);
