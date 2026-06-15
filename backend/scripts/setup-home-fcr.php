<?php

/**
 * Añade sala FCR_PANO al laboratorio principal para pruebas con Fuji en casa.
 * Uso: docker compose -f docker-compose.lan.yml exec -T api php scripts/setup-home-fcr.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Laboratory;
use App\Models\Machine;
use Illuminate\Support\Str;

$lab = Laboratory::query()->whereNull('parent_id')->orderBy('name')->first()
    ?? Laboratory::query()->orderBy('name')->first();

if (!$lab) {
    fwrite(STDERR, "No hay laboratorios. Ejecute: php artisan db:seed --force\n");
    exit(1);
}

$ae = 'FCR_PANO';
$existing = Machine::query()
    ->where('laboratory_id', $lab->id)
    ->where('ae_title', $ae)
    ->first();

if ($existing) {
    $existing->update([
        'name' => 'CR Pano (casa)',
        'group' => 'CR',
        'is_active' => true,
    ]);
    echo "Sala existente actualizada: {$existing->id} ({$ae})\n";
    exit(0);
}

$machine = Machine::create([
    'id' => (string) Str::uuid(),
    'laboratory_id' => $lab->id,
    'name' => 'CR Pano (casa)',
    'group' => 'CR',
    'ip_address' => getenv('MWL_DICOM_HOST') ?: '192.168.100.8',
    'ae_title' => $ae,
    'is_active' => true,
    'event_color' => '#3788d8',
]);

echo "Sala creada: {$machine->id} — AE {$ae}, lab {$lab->name}\n";
