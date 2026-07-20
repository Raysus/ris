<?php
/**
 * Compara pacientes Orthanc vs usuarios Keycloak (portal).
 * Uso en contenedor portal_app_nuevo:
 *   php /tmp/compare-orthanc-keycloak.php
 */

require __DIR__ . '/../../vendor/autoload.php';

// When copied to /tmp in container, bootstrap from app root:
$boot = '/var/www/html/bootstrap/app.php';
if (!is_file($boot)) {
    $boot = __DIR__ . '/../../bootstrap/app.php';
}
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

function formatRut(?string $rut): ?string
{
    $rut = preg_replace('/[^0-9Kk]/', '', (string) $rut);
    if (strlen($rut) < 2) {
        return null;
    }
    $dv = substr($rut, -1);
    $numero = substr($rut, 0, -1);

    return $numero . '-' . strtoupper($dv);
}

$orthancUrl = env('ORTHANC_URL', 'http://172.16.66.11:8042');
$baseUrl = rtrim((string) env('KEYCLOAK_BASE_URL'), '/');
$realm = (string) env('KEYCLOAK_REALM', 'patient-portal');

echo "ORTHANC_URL={$orthancUrl}\n";
echo "KEYCLOAK={$baseUrl} realm={$realm}\n";

$tokenRes = Http::withoutVerifying()->asForm()->post("{$baseUrl}/realms/{$realm}/protocol/openid-connect/token", [
    'grant_type' => 'client_credentials',
    'client_id' => env('KEYCLOAK_SERVICE_ACCOUNT_CLIENT_ID'),
    'client_secret' => env('KEYCLOAK_SERVICE_ACCOUNT_CLIENT_SECRET'),
]);
echo 'token_status=' . $tokenRes->status() . "\n";
if (!$tokenRes->successful()) {
    echo substr($tokenRes->body(), 0, 300) . "\n";
    exit(1);
}
$token = $tokenRes->json()['access_token'];

// Same window as SyncOrthancPatients (confirm from source) — default 2 years found historically; detect from env or 2y.
$years = (int) env('ORTHANC_SYNC_YEARS', 2);
$fechaDesde = Carbon::now()->subYears($years)->format('Ymd');
$fechaHasta = Carbon::now()->addDay()->format('Ymd');
echo "window_years={$years} window={$fechaDesde}-{$fechaHasta}\n";

$estudios = Http::withoutVerifying()->timeout(180)->post("{$orthancUrl}/tools/find", [
    'Level' => 'Study',
    'Query' => ['StudyDate' => "{$fechaDesde}-{$fechaHasta}"],
    'Expand' => true,
])->json();
if (!is_array($estudios)) {
    echo "orthanc_studies_fail\n";
    exit(1);
}
echo 'studies_window=' . count($estudios) . "\n";

$windowPatients = [];
$invalid = [];
foreach ($estudios as $estudio) {
    $tags = $estudio['PatientMainDicomTags'] ?? [];
    $rutRaw = $tags['PatientID'] ?? null;
    if (!$rutRaw) {
        $invalid[] = ['reason' => 'no_patientid', 'name' => $tags['PatientName'] ?? null];
        continue;
    }
    $fmt = formatRut(strtoupper(str_replace(['.', ' '], '', $rutRaw)));
    if (!$fmt) {
        $invalid[] = ['reason' => 'bad_rut', 'raw' => $rutRaw];
        continue;
    }
    if (!isset($windowPatients[$fmt])) {
        $windowPatients[$fmt] = [
            'raw' => $rutRaw,
            'name' => $tags['PatientName'] ?? null,
            'date' => $estudio['MainDicomTags']['StudyDate'] ?? null,
        ];
    }
}
echo 'unique_patients_window=' . count($windowPatients) . ' invalid=' . count($invalid) . "\n";

$ids = Http::withoutVerifying()->timeout(180)->get("{$orthancUrl}/patients")->json();
echo 'orthanc_patient_ids=' . (is_array($ids) ? count($ids) : 'fail') . "\n";
$all = [];
$allInvalid = 0;
foreach ((array) $ids as $pid) {
    $p = Http::withoutVerifying()->timeout(30)->get("{$orthancUrl}/patients/{$pid}")->json();
    if (!is_array($p)) {
        $allInvalid++;
        continue;
    }
    $tags = $p['MainDicomTags'] ?? [];
    $rutRaw = $tags['PatientID'] ?? null;
    if (!$rutRaw) {
        $allInvalid++;
        continue;
    }
    $fmt = formatRut(strtoupper(str_replace(['.', ' '], '', $rutRaw)));
    if (!$fmt) {
        $allInvalid++;
        continue;
    }
    $all[$fmt] = ['raw' => $rutRaw, 'name' => $tags['PatientName'] ?? null];
}
echo 'orthanc_unique_rut=' . count($all) . " invalid_all={$allInvalid}\n";

$kcUsers = [];
$first = 0;
$max = 100;
while (true) {
    $batch = Http::withToken($token)->withoutVerifying()
        ->get("{$baseUrl}/admin/realms/{$realm}/users", ['first' => $first, 'max' => $max])
        ->json();
    if (!is_array($batch) || $batch === []) {
        break;
    }
    foreach ($batch as $u) {
        $kcUsers[strtoupper((string) ($u['username'] ?? ''))] = $u;
    }
    if (count($batch) < $max) {
        break;
    }
    $first += $max;
    if ($first > 50000) {
        break;
    }
}
echo 'keycloak_users_total=' . count($kcUsers) . "\n";

$missingWindow = [];
foreach ($windowPatients as $rut => $info) {
    if (!isset($kcUsers[strtoupper($rut)])) {
        $missingWindow[] = ['rut' => $rut] + $info;
    }
}
$missingAll = [];
foreach ($all as $rut => $info) {
    if (!isset($kcUsers[strtoupper($rut)])) {
        $missingAll[] = ['rut' => $rut] + $info;
    }
}
echo 'missing_in_kc_from_window=' . count($missingWindow) . "\n";
echo 'missing_in_kc_from_all=' . count($missingAll) . "\n";
echo 'sample_missing_window=' . json_encode(array_slice($missingWindow, 0, 25), JSON_UNESCAPED_UNICODE) . "\n";
echo 'sample_missing_all=' . json_encode(array_slice($missingAll, 0, 25), JSON_UNESCAPED_UNICODE) . "\n";
echo 'sample_invalid=' . json_encode(array_slice($invalid, 0, 15), JSON_UNESCAPED_UNICODE) . "\n";

$outside = array_diff(array_keys($all), array_keys($windowPatients));
$outsideMissing = [];
foreach ($outside as $rut) {
    if (!isset($kcUsers[strtoupper($rut)])) {
        $outsideMissing[] = $rut;
    }
}
echo 'orthanc_outside_window=' . count($outside) . ' outside_missing_kc=' . count($outsideMissing) . "\n";
echo 'sample_outside_missing=' . json_encode(array_slice($outsideMissing, 0, 30), JSON_UNESCAPED_UNICODE) . "\n";

// Probe create for first missing (window preferred)
$probe = $missingWindow[0] ?? $missingAll[0] ?? null;
if ($probe) {
    $rut = $probe['rut'];
    $numero = explode('-', $rut)[0];
    $password = substr($numero, -4);
    $email = str_replace('-', '', strtolower($rut)) . '@paciente.healthticloud.cl';
    $exists = Http::withToken($token)->withoutVerifying()
        ->get("{$baseUrl}/admin/realms/{$realm}/users", ['username' => $rut, 'exact' => 'true'])
        ->json();
    echo 'probe_rut=' . $rut . ' exact_exists=' . (is_array($exists) ? count($exists) : 0) . "\n";
    $resp = Http::withToken($token)->withoutVerifying()->post("{$baseUrl}/admin/realms/{$realm}/users", [
        'username' => $rut,
        'enabled' => true,
        'firstName' => 'Probe',
        'lastName' => 'Sync',
        'email' => $email,
        'attributes' => ['rut' => [$rut]],
        'credentials' => [['type' => 'password', 'value' => $password, 'temporary' => true]],
    ]);
    echo 'probe_create_status=' . $resp->status() . ' body=' . substr($resp->body(), 0, 400) . "\n";
}
