<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

function fmt($r)
{
    $r = preg_replace('/[^0-9Kk]/', '', (string) $r);
    if (strlen($r) < 2) {
        return null;
    }

    return substr($r, 0, -1) . '-' . strtoupper(substr($r, -1));
}

$o = rtrim(env('ORTHANC_URL', 'http://172.16.66.11:8042'), '/');
$b = rtrim(env('KEYCLOAK_BASE_URL'), '/');
$realm = env('KEYCLOAK_REALM');
$tok = Http::withoutVerifying()->asForm()->post("{$b}/realms/{$realm}/protocol/openid-connect/token", [
    'grant_type' => 'client_credentials',
    'client_id' => env('KEYCLOAK_SERVICE_ACCOUNT_CLIENT_ID'),
    'client_secret' => env('KEYCLOAK_SERVICE_ACCOUNT_CLIENT_SECRET'),
])->json()['access_token'] ?? null;

if (!$tok) {
    echo "no_token\n";
    exit(1);
}

$kc = [];
$f = 0;
while (true) {
    $batch = Http::withToken($tok)->withoutVerifying()
        ->get("{$b}/admin/realms/{$realm}/users", ['first' => $f, 'max' => 100])
        ->json();
    if (!is_array($batch) || $batch === []) {
        break;
    }
    foreach ($batch as $u) {
        $kc[strtoupper((string) ($u['username'] ?? ''))] = 1;
    }
    if (count($batch) < 100) {
        break;
    }
    $f += 100;
}

$ids = Http::withoutVerifying()->timeout(180)->get("{$o}/patients")->json();
$miss = [];
$all = 0;
foreach ((array) $ids as $pid) {
    $p = Http::withoutVerifying()->get("{$o}/patients/{$pid}")->json();
    $raw = $p['MainDicomTags']['PatientID'] ?? null;
    if (!$raw) {
        continue;
    }
    $rut = fmt(strtoupper(str_replace(['.', ' '], '', $raw)));
    if (!$rut) {
        continue;
    }
    $all++;
    if (!isset($kc[strtoupper($rut)])) {
        $miss[] = $rut;
    }
}

echo 'orthanc_rut=' . $all . ' kc=' . count($kc) . ' missing=' . count($miss) . "\n";
echo 'sample_missing=' . json_encode(array_slice($miss, 0, 30), JSON_UNESCAPED_UNICODE) . "\n";
