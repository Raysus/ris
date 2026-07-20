<?php
/**
 * Crea en Keycloak solo los pacientes Orthanc que faltan.
 * Uso: php /tmp/create-missing-keycloak-patients.php
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function fmt($r)
{
    $r = preg_replace('/[^0-9Kk]/', '', (string) $r);
    if (strlen($r) < 2) {
        return null;
    }

    return substr($r, 0, -1) . '-' . strtoupper(substr($r, -1));
}

function tok(): ?string
{
    $b = rtrim(env('KEYCLOAK_BASE_URL'), '/');
    $realm = env('KEYCLOAK_REALM');
    $res = Http::withoutVerifying()->asForm()->post("{$b}/realms/{$realm}/protocol/openid-connect/token", [
        'grant_type' => 'client_credentials',
        'client_id' => env('KEYCLOAK_SERVICE_ACCOUNT_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_SERVICE_ACCOUNT_CLIENT_SECRET'),
    ]);

    return $res->json()['access_token'] ?? null;
}

$o = rtrim(env('ORTHANC_URL', 'http://172.16.66.11:8042'), '/');
$b = rtrim(env('KEYCLOAK_BASE_URL'), '/');
$realm = env('KEYCLOAK_REALM');
$token = tok();
$tokenAt = time();
if (!$token) {
    fwrite(STDERR, "no token\n");
    exit(1);
}

$kc = [];
$f = 0;
while (true) {
    if (time() - $tokenAt >= 240) {
        $token = tok() ?? $token;
        $tokenAt = time();
    }
    $batch = Http::withToken($token)->withoutVerifying()
        ->get("{$b}/admin/realms/{$realm}/users", ['first' => $f, 'max' => 100])->json();
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
$created = 0;
$exists = 0;
$errors = 0;
$local = 0;

foreach ((array) $ids as $pid) {
    if (time() - $tokenAt >= 240) {
        $token = tok() ?? $token;
        $tokenAt = time();
    }
    $p = Http::withoutVerifying()->get("{$o}/patients/{$pid}")->json();
    $raw = $p['MainDicomTags']['PatientID'] ?? null;
    if (!$raw) {
        continue;
    }
    $rut = fmt(strtoupper(str_replace(['.', ' '], '', $raw)));
    if (!$rut) {
        continue;
    }
    if (isset($kc[strtoupper($rut)])) {
        continue;
    }

    $nameRaw = $p['MainDicomTags']['PatientName'] ?? 'PACIENTE';
    $clean = str_replace('^', ' ', $nameRaw);
    $first = 'Paciente';
    $last = '';
    if (str_contains($clean, ',')) {
        $parts = explode(',', $clean);
        $last = trim($parts[0]);
        $first = trim($parts[1] ?? 'Paciente');
    } else {
        $parts = preg_split('/\s+/', trim($clean)) ?: [];
        if (count($parts) > 1) {
            $first = array_shift($parts);
            $last = implode(' ', $parts);
        } else {
            $first = $clean;
        }
    }
    $first = Str::title($first);
    $last = Str::title($last);
    $email = str_replace('-', '', strtolower($rut)) . '@paciente.healthticloud.cl';
    $password = substr(explode('-', $rut)[0], -4);

    $resp = Http::withToken($token)->withoutVerifying()->post("{$b}/admin/realms/{$realm}/users", [
        'username' => $rut,
        'enabled' => true,
        'firstName' => $first,
        'lastName' => $last,
        'email' => $email,
        'attributes' => ['rut' => [$rut]],
        'credentials' => [['type' => 'password', 'value' => $password, 'temporary' => false]],
    ]);

    if ($resp->status() === 201) {
        $created++;
        $kc[strtoupper($rut)] = 1;
        echo "CREATED {$rut}\n";
    } elseif ($resp->status() === 409) {
        $exists++;
        $kc[strtoupper($rut)] = 1;
        echo "EXISTS {$rut}\n";
    } else {
        $errors++;
        echo "ERR {$rut} status=" . $resp->status() . ' body=' . substr($resp->body(), 0, 120) . "\n";
        continue;
    }

    try {
        User::updateOrCreate(
            ['rut' => $rut],
            [
                'name' => trim($first . ' ' . $last),
                'role' => 'paciente',
                'laboratory_id' => 1,
                'site_filter' => 'ALL',
                'email' => $email,
                'password' => Hash::make($password),
            ]
        );
        $local++;
    } catch (Throwable $e) {
        echo "LOCAL_ERR {$rut} " . $e->getMessage() . "\n";
    }
}

echo "DONE created={$created} exists={$exists} local={$local} errors={$errors}\n";
