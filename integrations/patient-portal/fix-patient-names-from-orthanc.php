<?php
/**
 * Corrige nombres placeholder (Fix Sync / Probe Sync) desde Orthanc → Keycloak + BD portal.
 * Uso en portal_app_nuevo: php /tmp/fix-patient-names-from-orthanc.php
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function fmt(?string $rut): ?string
{
    $rut = preg_replace('/[^0-9Kk]/', '', (string) $rut);
    if (strlen($rut) < 2) {
        return null;
    }

    return substr($rut, 0, -1) . '-' . strtoupper(substr($rut, -1));
}

function parseDicomName(?string $name): array
{
    $clean = str_replace('^', ' ', (string) $name);
    $firstName = 'Paciente';
    $lastName = '';
    if (str_contains($clean, ',')) {
        $parts = explode(',', $clean);
        $lastName = trim($parts[0]);
        $firstName = trim($parts[1] ?? 'Paciente');
    } else {
        $parts = preg_split('/\s+/', trim($clean)) ?: [];
        if (count($parts) > 1) {
            $firstName = array_shift($parts);
            $lastName = implode(' ', $parts);
        } else {
            $firstName = $clean;
        }
    }

    return [
        'first' => Str::title($firstName),
        'last' => Str::title($lastName),
        'full' => trim(Str::title($firstName) . ' ' . Str::title($lastName)),
    ];
}

function tok(): ?string
{
    $b = rtrim((string) env('KEYCLOAK_BASE_URL'), '/');
    $realm = (string) env('KEYCLOAK_REALM');
    $res = Http::withoutVerifying()->asForm()->post("{$b}/realms/{$realm}/protocol/openid-connect/token", [
        'grant_type' => 'client_credentials',
        'client_id' => env('KEYCLOAK_SERVICE_ACCOUNT_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_SERVICE_ACCOUNT_CLIENT_SECRET'),
    ]);

    return $res->json()['access_token'] ?? null;
}

function isPlaceholderName(?string $first, ?string $last, ?string $full = null): bool
{
    $f = strtolower(trim((string) $first));
    $l = strtolower(trim((string) $last));
    $full = strtolower(trim((string) ($full ?? trim($first . ' ' . $last))));

    if (in_array($f, ['fix', 'probe', 'test'], true) && in_array($l, ['sync', 'probe'], true)) {
        return true;
    }
    if (str_contains($full, 'fix sync') || str_contains($full, 'probe sync')) {
        return true;
    }

    return false;
}

$o = rtrim((string) env('ORTHANC_URL', 'http://172.16.66.11:8042'), '/');
$b = rtrim((string) env('KEYCLOAK_BASE_URL'), '/');
$realm = (string) env('KEYCLOAK_REALM');
$token = tok();
if (!$token) {
    fwrite(STDERR, "no_keycloak_token\n");
    exit(1);
}

// Mapa RUT → PatientName desde Orthanc
$orthancByRut = [];
$ids = Http::withoutVerifying()->timeout(180)->get("{$o}/patients")->json();
foreach ((array) $ids as $pid) {
    $p = Http::withoutVerifying()->timeout(30)->get("{$o}/patients/{$pid}")->json();
    if (!is_array($p)) {
        continue;
    }
    $raw = $p['MainDicomTags']['PatientID'] ?? null;
    $rut = fmt(strtoupper(str_replace(['.', ' '], '', (string) $raw)));
    if (!$rut) {
        continue;
    }
    $orthancByRut[strtoupper($rut)] = $p['MainDicomTags']['PatientName'] ?? null;
}
echo 'orthanc_patients=' . count($orthancByRut) . "\n";

$fixedKc = 0;
$fixedLocal = 0;
$skipped = 0;
$missingOrthanc = 0;

// 1) Keycloak: buscar usuarios con nombre placeholder
$first = 0;
while (true) {
    $batch = Http::withToken($token)->withoutVerifying()
        ->get("{$b}/admin/realms/{$realm}/users", ['first' => $first, 'max' => 100])
        ->json();
    if (!is_array($batch) || $batch === []) {
        break;
    }
    foreach ($batch as $u) {
        $firstName = $u['firstName'] ?? '';
        $lastName = $u['lastName'] ?? '';
        if (!isPlaceholderName($firstName, $lastName)) {
            continue;
        }
        $rut = fmt(strtoupper(str_replace(['.', ' '], '', (string) ($u['username'] ?? ''))));
        if (!$rut) {
            $skipped++;
            continue;
        }
        $dicomName = $orthancByRut[strtoupper($rut)] ?? null;
        if (!$dicomName) {
            $missingOrthanc++;
            echo "SKIP_KC {$rut}: sin nombre en Orthanc\n";
            continue;
        }
        $parsed = parseDicomName($dicomName);
        $put = Http::withToken($token)->withoutVerifying()->put(
            "{$b}/admin/realms/{$realm}/users/{$u['id']}",
            [
                'username' => $u['username'],
                'email' => $u['email'] ?? null,
                'enabled' => $u['enabled'] ?? true,
                'firstName' => $parsed['first'],
                'lastName' => $parsed['last'],
                'attributes' => $u['attributes'] ?? ['rut' => [$rut]],
            ]
        );
        if ($put->successful() || $put->status() === 204) {
            $fixedKc++;
            echo "KC {$rut} => {$parsed['full']}\n";
        } else {
            echo "ERR_KC {$rut} status=" . $put->status() . ' ' . substr($put->body(), 0, 120) . "\n";
        }
    }
    if (count($batch) < 100) {
        break;
    }
    $first += 100;
}

// 2) BD local portal
$locals = User::query()
    ->where(function ($q) {
        $q->where('name', 'like', '%Fix Sync%')
            ->orWhere('name', 'like', '%Probe Sync%')
            ->orWhere('name', 'like', '%Fix%Sync%');
    })
    ->get();

foreach ($locals as $user) {
    $rut = fmt(strtoupper(str_replace(['.', ' '], '', (string) $user->rut)));
    if (!$rut) {
        $skipped++;
        continue;
    }
    $dicomName = $orthancByRut[strtoupper($rut)] ?? null;
    if (!$dicomName) {
        $missingOrthanc++;
        echo "SKIP_DB {$rut}: sin nombre en Orthanc\n";
        continue;
    }
    $parsed = parseDicomName($dicomName);
    $user->name = $parsed['full'];
    $user->save();
    $fixedLocal++;
    echo "DB {$rut} => {$parsed['full']}\n";
}

echo "DONE kc={$fixedKc} local={$fixedLocal} skip={$skipped} no_orthanc={$missingOrthanc}\n";
