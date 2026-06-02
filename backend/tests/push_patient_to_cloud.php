<?php

/** Envía persona+paciente de una cita local a la nube (workaround si inbound aún no anida patient). */

$root = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$appointmentId = $argv[1] ?? null;
if (!$appointmentId) {
    fwrite(STDERR, "Uso: php tests/push_patient_to_cloud.php <appointment-uuid>\n");
    exit(1);
}

$appointment = App\Models\Appointment::with('patient.persona')->find($appointmentId);
if (!$appointment?->patient?->persona) {
    fwrite(STDERR, "Cita o paciente no encontrado.\n");
    exit(1);
}

$cloudUrl = rtrim(config('cloud_sync.inbound_url'), '/');
$secret = config('cloud_sync.secret');
if (!$cloudUrl || !$secret) {
    fwrite(STDERR, "CLOUD_API_BASE / CLOUD_SYNC_SECRET no configurados.\n");
    exit(1);
}

$http = Illuminate\Support\Facades\Http::timeout(20)->withToken($secret)->acceptJson()->asJson();
if (app()->environment('local', 'testing')) {
    $http = $http->withoutVerifying();
}

$persona = $appointment->patient->persona->toArray();
$paciente = $appointment->patient->toArray();
$paciente['persona'] = $persona;

foreach (
    [
        ['model' => 'Persona', 'data' => $persona],
        ['model' => 'Paciente', 'data' => $paciente],
    ] as $payload
) {
    $r = $http->post($cloudUrl, array_merge($payload, ['action' => 'updated']));
    echo ($r->successful() ? '[OK]  ' : '[FAIL]') . " {$payload['model']} HTTP {$r->status()}\n";
    if (!$r->successful()) {
        echo substr($r->body(), 0, 400) . "\n";
        exit(1);
    }
}

echo "Paciente listo en nube para cita {$appointmentId}\n";
