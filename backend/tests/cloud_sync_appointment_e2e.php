<?php

/**
 * E2E: login rgutierrez → cita en RIS PRO → cola sync → log + verificación en nube.
 *
 * Uso:
 *   php tests/cloud_sync_appointment_e2e.php [base_url]
 *   php tests/cloud_sync_appointment_e2e.php http://127.0.0.1:8000
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
if (!str_ends_with($base, '/api')) {
    $base .= '/api';
}

$cloudApi = rtrim(getenv('CLOUD_API_BASE') ?: 'https://api.healthticloud.cl/api', '/');
$cloudSecret = getenv('CLOUD_SYNC_SECRET') ?: '';

$failed = 0;

function http(string $method, string $url, $body = null, array $extraHeaders = [], bool $multipart = false): array
{
    $headers = array_merge(['Accept: application/json'], $extraHeaders);
    $content = null;

    if ($body !== null && !$multipart) {
        $headers[] = 'Content-Type: application/json';
        $content = is_string($body) ? $body : json_encode($body);
    }

    $ctx = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 120,
        ],
    ];

    if ($multipart && is_array($body)) {
        $boundary = 'risE2E' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
        $parts = '';
        foreach ($body as $name => $value) {
            $parts .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        $parts .= "--{$boundary}--\r\n";
        $content = $parts;
        $ctx['http']['header'] = implode("\r\n", $headers) . "\r\n";
        $ctx['http']['content'] = $content;
    } elseif ($content !== null) {
        $ctx['http']['content'] = $content;
    }

    $raw = @file_get_contents($url, false, stream_context_create($ctx));
    preg_match('/\d{3}/', $http_response_header[0] ?? '0', $m);

    return ['code' => (int) ($m[0] ?? 0), 'json' => json_decode($raw ?: '', true), 'raw' => $raw ?: ''];
}

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $failed;
    if ($cond) {
        echo "[OK]   {$label}\n";
    } else {
        $failed++;
        $detail = $detail !== '' ? " — {$detail}" : '';
        echo "[FAIL] {$label}{$detail}\n";
    }
}

echo "Cloud sync E2E → local {$base}\n\n";

$login = http('POST', "$base/login", ['login_field' => 'rgutierrez', 'password' => 'rgutierrez']);
$token = $login['json']['access_token'] ?? null;
ok('Login rgutierrez', $token !== null, $login['json']['message'] ?? $login['raw']);

if (!$token) {
    exit(1);
}

$auth = ["Authorization: Bearer $token"];
$labsRes = http('GET', "$base/laboratories", null, $auth);
$risProId = null;
foreach ($labsRes['json']['data'] ?? [] as $matriz) {
    $name = $matriz['name'] ?? '';
    if (stripos($name, 'RIS PRO') !== false) {
        $risProId = $matriz['id'];
        break;
    }
}
ok('Laboratorio RIS PRO', $risProId !== null);
if (!$risProId) {
    exit(1);
}

$labHeaders = array_merge($auth, ["X-Lab-Id: {$risProId}"]);
$machines = http('GET', "$base/machines", null, $labHeaders);
$machine = ($machines['json']['data'] ?? [])[0] ?? null;
ok('Máquina en RIS PRO', $machine !== null);

$exams = http('GET', "$base/exams", null, $labHeaders);
$exam = ($exams['json']['data'] ?? [])[0] ?? null;
ok('Examen en RIS PRO', $exam !== null);

if (!$machine || !$exam) {
    exit(1);
}

$marker = 'E2E-' . gmdate('Ymd-His');
$rut = sprintf('%08d-%s', random_int(10000000, 99999999), (string) random_int(0, 9));
$slotStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify('+' . random_int(10, 40) . ' days')
    ->setTime(random_int(9, 16), [0, 15, 30, 45][random_int(0, 3)], 0);
$slotEnd = $slotStart->modify('+30 minutes');

$payload = [
    'start_time' => $slotStart->format('Y-m-d\TH:i:s'),
    'end_time' => $slotEnd->format('Y-m-d\TH:i:s'),
    'machine_id' => $machine['id'],
    'status' => 'agendado',
    'patient' => [
        'rut' => $rut,
        'names' => 'Paciente',
        'last_name_1' => $marker,
        'last_name_2' => 'CloudSync',
        'gender' => 'M',
        'birth_date' => '1985-06-15',
        'email' => strtolower($marker) . '@e2e.test',
        'phone' => '+56911112222',
        'insurance_id' => null,
        'insurance_plan_id' => null,
    ],
    'studies' => [
        [
            'machine_id' => $machine['id'],
            'exam_id' => $exam['id'],
            'exam_name' => $exam['name'] ?? 'Examen E2E',
            'fonasa_code' => $exam['fonasa_code'] ?? null,
            'quantity' => 1,
            'price' => (float) ($exam['price'] ?? 0),
        ],
    ],
    'supplies' => [],
    'origin' => 'Ambulatorio',
    'priority' => 'Normal',
    'payment_method' => 'Efectivo',
    'payment_status' => 'Pendiente',
    'notes' => $marker,
];

$create = http('POST', "$base/appointments", ['data' => json_encode($payload)], $labHeaders, true);
$appointmentId = $create['json']['appointment']['id'] ?? null;
ok('Crear cita en RIS PRO', $create['code'] === 201 && $appointmentId, $create['json']['message'] ?? substr($create['raw'], 0, 200));

if (!$appointmentId) {
    exit(1);
}

echo "       Cita id={$appointmentId}, paciente RUT={$rut}, marcador={$marker}\n";

// Dar tiempo al worker de cola (contenedor queue)
$maxWait = 45;
$syncOk = false;
$lastLog = null;
for ($i = 0; $i < $maxWait; $i++) {
    sleep(2);
    $logs = http('GET', "$base/integrations/cloud-sync?limit=5", null, $auth);
    foreach ($logs['json']['data']['logs'] ?? $logs['json']['data'] ?? [] as $log) {
        $payloadStr = is_array($log['payload'] ?? null) ? json_encode($log['payload']) : (string) ($log['payload'] ?? '');
        if (($log['entity_id'] ?? '') === $appointmentId || str_contains($payloadStr, $appointmentId)) {
            $lastLog = $log;
            if (($log['status'] ?? '') === 'success') {
                $syncOk = true;
                break 2;
            }
            if (in_array($log['status'] ?? '', ['failed', 'skipped'], true)) {
                break 2;
            }
        }
    }
}

if ($lastLog) {
    echo "       cloud_sync_log: status={$lastLog['status']}, model=" . ($lastLog['model'] ?? '?') . "\n";
    if (!empty($lastLog['last_error'])) {
        echo "       error: " . substr((string) $lastLog['last_error'], 0, 300) . "\n";
    }
} else {
    echo "       (sin fila en integrations/cloud-sync; revise worker queue)\n";
}

ok('Sync local → nube (cloud_sync_logs success)', $syncOk);

// Verificar en nube: misma cita por ID (export no expone citas; login nube + GET appointment)
if ($cloudSecret !== '') {
    $cloudLogin = http('POST', "{$cloudApi}/login", ['login_field' => 'rgutierrez', 'password' => 'rgutierrez']);
    $cloudToken = $cloudLogin['json']['access_token'] ?? null;
    if ($cloudToken) {
        $cloudAppt = http('GET', "{$cloudApi}/appointments/{$appointmentId}", null, [
            "Authorization: Bearer $cloudToken",
            "X-Lab-Id: {$risProId}",
        ]);
        $found = $cloudAppt['code'] === 200 && ($cloudAppt['json']['success'] ?? false) === true;
        ok('Cita visible en API nube (mismo UUID)', $found, "HTTP {$cloudAppt['code']}");
        if ($found) {
            $p = $cloudAppt['json']['data']['patient']['persona'] ?? $cloudAppt['json']['appointment']['patient']['persona'] ?? [];
            $ln1 = $p['last_name_1'] ?? '';
            ok('Paciente en nube coincide (marcador)', $ln1 === $marker, "last_name_1={$ln1}");
        }
    } else {
        ok('Login nube rgutierrez', false, $cloudLogin['json']['message'] ?? 'sin token');
    }
} else {
    echo "[SKIP] CLOUD_SYNC_SECRET no definido en entorno — omitida verificación en nube\n";
}

echo "\n" . ($failed === 0 ? "E2E cloud sync: OK\n" : "E2E cloud sync: {$failed} fallo(s)\n");
exit($failed > 0 ? 1 : 0);
