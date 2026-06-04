<?php

/**
 * E2E sync nube ↔ catálogo y operación por módulo.
 *
 * Uso:
 *   php tests/cloud_sync_modules_e2e.php [api_base] [cloud_sync_secret]
 *
 * Si omite secret, intenta cargar .env vía bootstrap Laravel.
 * api_base por defecto: https://api.healthticloud.cl/api
 */

$root = is_file(__DIR__ . '/../vendor/autoload.php') ? dirname(__DIR__) : __DIR__;
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}

$base = rtrim($argv[1] ?? getenv('API_BASE') ?: 'https://api.healthticloud.cl/api', '/');
if (!str_ends_with($base, '/api')) {
    $base .= '/api';
}

$syncSecret = $argv[2] ?? getenv('CLOUD_SYNC_SECRET') ?: (function () {
    return function_exists('config') ? (string) config('cloud_sync.secret') : '';
})();

$failed = 0;
$marker = 'SYNC-E2E-' . gmdate('Ymd-His');

function httpReq(string $method, string $url, $body = null, array $extraHeaders = []): array
{
    $headers = array_merge(['Accept: application/json'], $extraHeaders);
    $content = null;

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        if ($content !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'json' => json_decode($raw ?: '', true), 'raw' => $raw ?: ''];
    }

    $ctx = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 120,
        ],
    ];
    if ($content !== null) {
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
        return;
    }
    $failed++;
    echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function inbound(string $cloudBase, string $secret, string $model, array $data, string $action = 'updated'): array
{
    return httpReq('POST', "{$cloudBase}/integrations/cloud-sync/inbound", [
        'model' => $model,
        'action' => $action,
        'data' => $data,
    ], ["Authorization: Bearer {$secret}"]);
}

function waitSyncLog(string $base, array $auth, string $entityId, string $entityType, int $maxSec = 40): ?array
{
    for ($i = 0; $i < $maxSec; $i += 2) {
        sleep(2);
        $logs = httpReq('GET', "{$base}/integrations/cloud-sync?limit=30", null, $auth);
        foreach ($logs['json']['data']['logs'] ?? [] as $log) {
            $matchId = ($log['entity_id'] ?? '') === $entityId;
            $matchType = str_contains((string) ($log['entity_type'] ?? ''), $entityType)
                || str_contains((string) ($log['entity_type'] ?? ''), class_basename($entityType));
            $payloadStr = json_encode($log['payload'] ?? []);
            if ($matchId || str_contains($payloadStr, $entityId)) {
                if (($log['status'] ?? '') === 'success') {
                    return $log;
                }
                if (in_array($log['status'] ?? '', ['failed', 'skipped'], true)) {
                    return $log;
                }
            }
        }
    }

    return null;
}

echo "=== Cloud sync módulos E2E ===\n";
echo "API: {$base}\n";
echo "Marcador: {$marker}\n\n";

if ($syncSecret === '') {
    echo "[FAIL] CLOUD_SYNC_SECRET no configurado (argumento 2 o .env)\n";
    exit(1);
}

$syncHeaders = ["Authorization: Bearer {$syncSecret}"];

// --- 0. Salud y auth ---
$health = httpReq('GET', "{$base}/health");
ok('Health API', $health['code'] === 200, "HTTP {$health['code']}");

$e2eUser = getenv('E2E_USER') ?: 'rgutierrez';
$e2ePass = getenv('E2E_PASSWORD') ?: 'rgutierrez';
$token = getenv('E2E_TOKEN') ?: ($argv[3] ?? null);

if (!$token) {
    $login = httpReq('POST', "{$base}/login", ['login_field' => $e2eUser, 'password' => $e2ePass]);
    $token = $login['json']['access_token'] ?? null;
    if ($token) {
        ok('Login admin (' . $e2eUser . ')', true);
    }
}

if (!$token && class_exists(\App\Models\User::class)) {
    $user = \App\Models\User::where('username', $e2eUser)->where('is_active', true)->first();
    if ($user) {
        $token = $user->createToken('cloud-sync-modules-e2e')->plainTextToken;
        echo "[INFO] Token Sanctum vía bootstrap (login HTTP no disponible en nube)\n";
        ok('Auth bootstrap Sanctum', true);
    }
} elseif (!$token) {
    ok('Login admin (' . $e2eUser . ')', false, 'sin token');
}

if (!$token) {
    echo "[FAIL] Sin token (E2E_TOKEN, login o bootstrap)\n";
    exit(1);
}
$auth = ["Authorization: Bearer {$token}"];

$status = httpReq('GET', "{$base}/integrations/cloud-sync/status", null, $auth);
ok('GET cloud-sync/status', ($status['json']['success'] ?? false) === true);
if ($status['json']['data'] ?? null) {
    echo "       role=" . ($status['json']['data']['role'] ?? '?')
        . " push=" . (($status['json']['data']['can_push'] ?? false) ? 'yes' : 'no')
        . " pull=" . (($status['json']['data']['can_pull'] ?? false) ? 'yes' : 'no') . "\n";
}

// Laboratorio RIS PRO para pruebas con X-Lab-Id
$risProId = null;
$insCode = null;
$labs = httpReq('GET', "{$base}/laboratories", null, $auth);
foreach ($labs['json']['data'] ?? [] as $matriz) {
    if (stripos($matriz['name'] ?? '', 'RIS PRO') !== false) {
        $risProId = $matriz['id'];
        break;
    }
}
ok('Laboratorio RIS PRO', $risProId !== null);
$labHeaders = $risProId ? array_merge($auth, ["X-Lab-Id: {$risProId}"]) : $auth;

// --- 1. Nube ← inbound (simula envío desde laboratorio) por módulo catálogo ---
echo "\n--- Dirección: inbound → nube (catálogo) ---\n";

$testIds = [
    'referring_doctor' => uuid(),
    'exam' => uuid(),
    'machine' => uuid(),
    'supply' => uuid(),
    'service' => uuid(),
    'insurance' => uuid(),
    'insurance_plan' => uuid(),
    'report_template' => uuid(),
];

$inboundCases = [
    ['ReferringDoctor', [
        'id' => $testIds['referring_doctor'],
        'rut' => '99.999.999-9',
        'names' => $marker,
        'last_name_1' => 'SyncDoc',
        'phone' => '+56900001111',
        'email' => strtolower($marker) . '@sync.test',
    ]],
    ['Exam', [
        'id' => $testIds['exam'],
        'laboratory_id' => $risProId,
        'group_code' => 'CT',
        'name' => $marker . ' Examen',
        'fonasa_code' => 'E2E01',
        'price' => 15000,
        'estimated_duration' => 30,
        'is_active' => true,
    ]],
    ['Machine', [
        'id' => $testIds['machine'],
        'laboratory_id' => $risProId,
        'name' => $marker . ' MR',
        'ae_title' => 'E2E_AET',
        'ip_address' => '127.0.0.1',
        'port' => 104,
        'is_active' => true,
    ]],
    ['Supply', [
        'id' => $testIds['supply'],
        'laboratory_id' => $risProId,
        'category' => 'E2E',
        'name' => $marker . ' Insumo',
        'stock' => 10,
        'max_stock' => 100,
        'price' => 500,
        'is_active' => true,
    ]],
    ['Service', [
        'id' => $testIds['service'],
        'laboratory_id' => $risProId,
        'name' => $marker . ' Servicio',
        'description' => 'E2E',
        'is_active' => true,
    ]],
];

if ($risProId) {
    $insCode = 'E2E-' . substr(md5($marker), 0, 8);
    $insRes = inbound($base, $syncSecret, 'Insurance', [
        'id' => $testIds['insurance'],
        'laboratory_id' => $risProId,
        'code' => $insCode,
        'name' => $marker . ' Prevision',
        'type' => 'privada',
        'is_active' => true,
    ]);
    ok('Inbound Insurance → nube', ($insRes['json']['success'] ?? false) === true, "HTTP {$insRes['code']}");

    $resolvedInsId = $testIds['insurance'];
    $insList = httpReq('GET', "{$base}/insurances", null, $labHeaders);
    foreach ($insList['json']['data'] ?? [] as $ins) {
        if (($ins['code'] ?? '') === $insCode || ($ins['id'] ?? '') === $testIds['insurance']) {
            $resolvedInsId = $ins['id'];
            break;
        }
    }

    $inboundCases[] = ['InsurancePlan', [
        'id' => $testIds['insurance_plan'],
        'insurance_id' => $resolvedInsId,
        'laboratory_id' => $risProId,
        'name' => $marker . ' Plan',
        'is_active' => true,
    ]];
}

foreach ($inboundCases as [$model, $data]) {
    if (empty($data['laboratory_id']) && $model !== 'ReferringDoctor' && $model !== 'InsurancePlan') {
        continue;
    }
    if ($model === 'InsurancePlan' && empty($data['insurance_id'])) {
        continue;
    }
    $r = inbound($base, $syncSecret, $model, $data);
    ok("Inbound {$model} → nube", ($r['json']['success'] ?? false) === true, substr($r['raw'], 0, 120));
}

$tplRes = inbound($base, $syncSecret, 'ReportTemplate', [
    'id' => $testIds['report_template'],
    'laboratory_id' => $risProId,
    'group_code' => 'CT',
    'title' => $marker . ' Plantilla',
    'content' => '<p>E2E</p>',
]);
ok('Inbound ReportTemplate → nube', ($tplRes['json']['success'] ?? false) === true, "HTTP {$tplRes['code']}");

// Persona + Paciente + Cita (bundle operación)
$personaId = uuid();
$pacienteId = uuid();
$appointmentId = uuid();
$machines = httpReq('GET', "{$base}/machines", null, $labHeaders);
$machine = ($machines['json']['data'] ?? [])[0] ?? null;
$examsList = httpReq('GET', "{$base}/exams", null, $labHeaders);
$examRow = ($examsList['json']['data'] ?? [])[0] ?? null;

if ($risProId && $machine && $examRow) {
    $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+45 days');
    $apptInbound = inbound($base, $syncSecret, 'App\Models\Appointment', [
        'id' => $appointmentId,
        'laboratory_id' => $risProId,
        'patient_id' => $pacienteId,
        'machine_id' => $machine['id'],
        'start_time' => $start->format('Y-m-d\TH:i:s'),
        'end_time' => $start->modify('+30 minutes')->format('Y-m-d\TH:i:s'),
        'status' => 'agendado',
        'payment_status' => 'Pendiente',
        'origin' => 'Ambulatorio',
        'notes' => $marker,
        'patient' => [
            'id' => $pacienteId,
            'laboratory_id' => $risProId,
            'persona_id' => $personaId,
            'persona' => [
                'id' => $personaId,
                'rut' => sprintf('11.%03d.111-%d', random_int(100, 999), random_int(0, 9)),
                'names' => 'Pac',
                'last_name_1' => $marker,
                'gender' => 'M',
                'birth_date' => '1990-01-01',
            ],
        ],
        'studies' => [],
    ], 'created');
    ok('Inbound Appointment+Patient → nube', ($apptInbound['json']['success'] ?? false) === true, "HTTP {$apptInbound['code']}");
}

// --- 2. Export nube (lectura catálogo) ---
echo "\n--- Dirección: export nube (pull source) ---\n";

$export = httpReq('GET', "{$base}/integrations/cloud-sync/export" . ($risProId ? "?laboratory_id={$risProId}" : ''), null, $syncHeaders);
ok('GET export catálogo', ($export['json']['success'] ?? false) === true, "HTTP {$export['code']}");

$catalogKeys = ['laboratories', 'referring_doctors', 'exams', 'machines', 'supplies', 'report_templates', 'insurances', 'insurance_plans', 'services'];
foreach ($catalogKeys as $key) {
    $count = is_array($export['json']['data'][$key] ?? null) ? count($export['json']['data'][$key]) : 0;
    ok("Export incluye {$key}", $count > 0, "count={$count}");
}

$foundDoctor = false;
foreach ($export['json']['data']['referring_doctors'] ?? [] as $row) {
    if (($row['names'] ?? '') === $marker) {
        $foundDoctor = true;
        break;
    }
}
ok('Export contiene médico E2E', $foundDoctor);

// --- 3. Pull catálogo (nube → aplicar en misma API / snapshot) ---
echo "\n--- Dirección: pull-catalog ---\n";

$pull = httpReq('POST', "{$base}/integrations/cloud-sync/pull-catalog", [
    'laboratory_id' => $risProId,
    'include_patients' => false,
], $labHeaders);
ok('POST pull-catalog', ($pull['json']['success'] ?? false) === true, $pull['json']['message'] ?? substr($pull['raw'], 0, 150));
if (!empty($pull['json']['data']['counts'])) {
    echo '       counts: ' . json_encode($pull['json']['data']['counts'], JSON_UNESCAPED_UNICODE) . "\n";
}

// --- 4. Salida hacia nube: push (local) o inbound update (simula LAN→nube) ---
$cloudRole = $status['json']['data']['role'] ?? 'cloud';
$canPush = (bool) ($status['json']['data']['can_push'] ?? false);
echo "\n--- Dirección: hacia nube (push o inbound-update) role={$cloudRole} ---\n";

$roundTripModels = [
    ['Exam', $testIds['exam'], ['group_code' => 'CT', 'name' => $marker . ' RT-Exam', 'fonasa_code' => 'E2E01', 'price' => 15000, 'estimated_duration' => 30, 'is_active' => true]],
    ['Machine', $testIds['machine'], ['name' => $marker . ' RT-MR', 'ae_title' => 'E2E_AET', 'ip_address' => '127.0.0.1', 'port' => 104, 'is_active' => true]],
    ['Supply', $testIds['supply'], ['category' => 'E2E', 'name' => $marker . ' RT-Insumo', 'stock' => 11, 'max_stock' => 100, 'price' => 500, 'is_active' => true]],
    ['Service', $testIds['service'], ['name' => $marker . ' RT-Svc', 'description' => 'E2E', 'is_active' => true]],
    ['ReferringDoctor', $testIds['referring_doctor'], ['rut' => '99.999.999-9', 'names' => $marker . '-RT', 'last_name_1' => 'SyncDoc', 'phone' => '+56900001111', 'email' => 'rt@sync.test']],
    ['ReportTemplate', $testIds['report_template'], ['group_code' => 'CT', 'title' => $marker . ' RT-Tpl', 'content' => '<p>RT</p>']],
];

foreach ($roundTripModels as [$model, $entityId, $extra]) {
    if (!$risProId && $model !== 'ReferringDoctor') {
        continue;
    }
    $payload = array_merge(['id' => $entityId, 'laboratory_id' => $risProId], $extra);
    if ($canPush && $model === 'Exam' && $examRow) {
        $put = httpReq('PUT', "{$base}/exams/{$examRow['id']}", [
            'group_code' => $examRow['group_code'] ?? 'CT',
            'name' => $marker . ' PushExam',
            'fonasa_code' => $examRow['fonasa_code'] ?? 'E2E',
            'price' => (float) ($examRow['price'] ?? 1000),
            'estimated_duration' => (int) ($examRow['estimated_duration'] ?? 30),
            'is_active' => true,
        ], $labHeaders);
        ok("PUT exam → cola push ({$model})", $put['code'] === 200, "HTTP {$put['code']}");
        $log = waitSyncLog($base, $auth, $examRow['id'], 'Exam');
        ok('cloud_sync_log Exam', $log && ($log['status'] ?? '') === 'success', $log['status'] ?? 'no log');
        continue;
    }
    $rt = inbound($base, $syncSecret, $model, $payload, 'updated');
    ok("Inbound update {$model} (simula push)", ($rt['json']['success'] ?? false) === true, "HTTP {$rt['code']}");
}

if ($risProId && !empty($testIds['insurance']) && !empty($insCode ?? null)) {
    $rtIns = inbound($base, $syncSecret, 'Insurance', [
        'id' => $testIds['insurance'],
        'laboratory_id' => $risProId,
        'code' => $insCode,
        'name' => $marker . ' RT-Prevision',
        'type' => 'privada',
        'is_active' => true,
    ], 'updated');
    ok('Inbound update Insurance', ($rtIns['json']['success'] ?? false) === true, "HTTP {$rtIns['code']}");
}

if ($canPush && $risProId) {
    $supplies = httpReq('GET', "{$base}/supplies", null, $labHeaders);
    $supply = ($supplies['json']['data'] ?? [])[0] ?? null;
    if ($supply) {
        $putS = httpReq('POST', "{$base}/supplies", [
            'id' => $supply['id'],
            'category' => $supply['category'] ?? 'Gen',
            'name' => $marker . ' PushSupply',
            'stock' => (int) ($supply['stock'] ?? 1),
            'max_stock' => (int) ($supply['max_stock'] ?? 10),
        ], $labHeaders);
        ok('POST supply (push local)', $putS['code'] === 200, "HTTP {$putS['code']}");
        $logS = waitSyncLog($base, $auth, $supply['id'], 'Supply');
        ok('cloud_sync_log Supply', $logS && ($logS['status'] ?? '') === 'success', $logS['status'] ?? 'no log');
    }
}

if ($canPush) {
    $docCreate = httpReq('POST', "{$base}/referring-doctors", [
        'rut' => '88.888.888-8',
        'names' => $marker,
        'last_name_1' => 'PushRef',
        'phone' => '+56988887777',
        'email' => 'pushref@e2e.test',
    ], $labHeaders);
    $docId = $docCreate['json']['data']['id'] ?? $docCreate['json']['id'] ?? null;
    ok('POST referring-doctors (push local)', $docCreate['code'] === 201 || $docCreate['code'] === 200, "HTTP {$docCreate['code']}");
    if ($docId) {
        $logD = waitSyncLog($base, $auth, $docId, 'ReferringDoctor');
        ok('cloud_sync_log ReferringDoctor', $logD && ($logD['status'] ?? '') === 'success', $logD['status'] ?? 'no log');
    }
}

// Verificar entidades E2E en export tras round-trip
$export2 = httpReq('GET', "{$base}/integrations/cloud-sync/export" . ($risProId ? "?laboratory_id={$risProId}" : ''), null, $syncHeaders);
foreach (['exams' => $marker . ' RT-Exam', 'machines' => $marker . ' RT-MR', 'supplies' => $marker . ' RT-Insumo'] as $key => $needle) {
    $found = false;
    foreach ($export2['json']['data'][$key] ?? [] as $row) {
        if (str_contains((string) ($row['name'] ?? ''), $needle)) {
            $found = true;
            break;
        }
    }
    ok("Export post-RT {$key}", $found, $found ? '' : 'no encontrado');
}

echo "\n" . ($failed === 0 ? "E2E módulos cloud sync: OK ({$marker})\n" : "E2E módulos cloud sync: {$failed} fallo(s) ({$marker})\n");
exit($failed > 0 ? 1 : 0);
