<?php

$root = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$appointmentId = $argv[1] ?? '';
$appointment = App\Models\Appointment::with('patient.persona')->find($appointmentId);
if (!$appointment?->patient) {
    fwrite(STDERR, "no appointment\n");
    exit(1);
}

$paciente = $appointment->patient->toArray();
$paciente['persona'] = $appointment->patient->persona->toArray();

try {
    (new App\Services\CloudEntitySyncService())->apply('Paciente', 'updated', $paciente);
    $id = $paciente['id'];
    $found = App\Models\Paciente::withoutGlobalScopes()->find($id);
    echo $found ? "LOCAL apply ok patient {$id}\n" : "LOCAL apply ran but patient missing\n";
} catch (Throwable $e) {
    echo "ERR: {$e->getMessage()}\n";
    exit(1);
}
