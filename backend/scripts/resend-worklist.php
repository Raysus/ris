<?php

/**
 * Uso (en servidor SIRESA):
 * docker compose -f docker-compose.lan.yml exec -T api php scripts/resend-worklist.php ACC-20260609-019EAD93
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\WorklistController;
use App\Models\Appointment;
use App\Services\DicomImportService;
use Illuminate\Http\Request;

$accession = $argv[1] ?? null;
if (!$accession) {
    fwrite(STDERR, "Uso: php scripts/resend-worklist.php <accession_number|appointment_uuid>\n");
    exit(1);
}

$appointment = Appointment::query()
    ->with(['patient.persona', 'machine', 'studies.machine', 'studies.exam', 'laboratory'])
    ->when(
        str_contains($accession, '-'),
        fn ($q) => $q->where('accession_number', $accession),
        fn ($q) => $q->where('id', $accession)
    )
    ->first();

if (!$appointment) {
    fwrite(STDERR, "Cita no encontrada: {$accession}\n");
    exit(1);
}

config(['app.allowed_lab_ids' => ['*']]);

$controller = app(WorklistController::class);
$response = $controller->sendToDicom(new Request(), $appointment->id, app(DicomImportService::class));
$data = $response->getData(true);

echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit(($data['success'] ?? false) ? 0 : 1);
