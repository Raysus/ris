<?php

/**
 * Alinea el catálogo del lab Siresa en la nube con el servidor local (fuente operativa).
 * Uso en nube: php scripts/align-siresa-catalog.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Machine;
use App\Services\CatalogDedupeService;
use Illuminate\Support\Facades\DB;

$labId = 'ef633609-3144-17e1-7086-3eb7c8baed74';

/** Catálogo canónico (Siresa local, jun 2026). */
$machines = [
    [
        'id' => '019e991e-859e-738f-94e6-2c37efcdca96',
        'name' => 'CR Pano',
        'group' => 'CR',
        'ae_title' => 'FCR_PANO',
        'ip_address' => '192.168.0.128',
        'port' => '104',
        'manufacturer' => 'Fuji',
        'model_name' => 'FCR',
    ],
    [
        'id' => '019e948e-09ee-7056-aa06-41a6b4889d5c',
        'name' => 'Eco Mindray',
        'group' => 'US',
        'ae_title' => 'HDI5000',
        'ip_address' => '192.168.0.108',
        'port' => '104',
        'manufacturer' => 'Mindray',
        'model_name' => 'DC70',
    ],
    [
        'id' => '019eccc3-c3d9-70fa-84a6-99cc7bbd83a1',
        'name' => 'Eco Sonoscape',
        'group' => 'US',
        'ae_title' => 'HDI5000',
        'ip_address' => '192.168.0.107',
        'port' => '104',
        'manufacturer' => 'Sonoscape',
        'model_name' => null,
    ],
    [
        'id' => '019eccc3-c359-71c3-945d-fc5399095224',
        'name' => 'Mamo',
        'group' => 'MAMO',
        'ae_title' => 'FCR_MAMO',
        'ip_address' => '192.168.0.103',
        'port' => '104',
        'manufacturer' => 'Fuji',
        'model_name' => 'FCR',
    ],
    [
        'id' => '019eccc3-c454-7190-a5c7-340d6abaec53',
        'name' => 'Rayos DX',
        'group' => 'DX',
        'ae_title' => 'FCR_PACS',
        'ip_address' => '192.168.0.102',
        'port' => '104',
        'manufacturer' => 'Fuji',
        'model_name' => 'FDR SMART',
    ],
];

function reassignMachine(string $fromId, string $toId): int
{
    $n = 0;
    $n += DB::table('appointments')->where('machine_id', $fromId)->update(['machine_id' => $toId]);
    $n += DB::table('appointment_studies')->where('machine_id', $fromId)->update(['machine_id' => $toId]);

    return $n;
}

/** HasUuids ignora id explícito en create; usar DB directo para UUIDs canónicos. */
function upsertMachine(array $row, string $labId): void
{
    $now = now();
    $payload = $row + [
        'laboratory_id' => $labId,
        'is_active' => true,
        'updated_at' => $now,
    ];

    if (DB::table('machines')->where('id', $row['id'])->exists()) {
        DB::table('machines')->where('id', $row['id'])->update($payload);

        return;
    }

    DB::table('machines')->insert($payload + [
        'created_at' => $now,
        'event_color' => '#3788d8',
    ]);
}

echo "=== Alinear máquinas Siresa en nube ===\n";

foreach ($machines as $row) {
    upsertMachine($row, $labId);
    echo "  OK {$row['name']} ({$row['ae_title']}) → {$row['id']}\n";
}

$canonicalNames = array_column($machines, 'name');
$wrongIds = Machine::query()
    ->where('laboratory_id', $labId)
    ->whereIn('name', $canonicalNames)
    ->whereNotIn('id', array_column($machines, 'id'))
    ->pluck('id', 'name');

foreach ($machines as $row) {
    $wrongId = $wrongIds[$row['name']] ?? null;
    if (!$wrongId) {
        continue;
    }
    $moved = reassignMachine((string) $wrongId, $row['id']);
    Machine::where('id', $wrongId)->delete();
    echo "  Migrado {$row['name']} alias {$wrongId} → {$row['id']} ({$moved} refs)\n";
}

$migrations = [
    ['019e9434-8dc9-7201-b20c-dd96726c7b09', '019eccc3-c359-71c3-945d-fc5399095224', 'Mamo duplicada'],
    ['019e9924-be88-71a7-8ea6-e36c899f259f', '019eccc3-c3d9-70fa-84a6-99cc7bbd83a1', 'Eco Sonoscape ID legacy'],
];

foreach ($migrations as [$from, $to, $label]) {
    if (!Machine::find($from)) {
        continue;
    }
    if (!Machine::find($to)) {
        echo "  Crear destino {$to} antes de migrar {$label}\n";
    }
    $moved = reassignMachine($from, $to);
    Machine::where('id', $from)->delete();
    echo "  {$label}: {$moved} referencias → {$to}, eliminado {$from}\n";
}

$keepIds = array_column($machines, 'id');
$extras = Machine::where('laboratory_id', $labId)->whereNotIn('id', $keepIds)->get();
foreach ($extras as $extra) {
    $used = (int) DB::table('appointments')->where('machine_id', $extra->id)->count()
        + (int) DB::table('appointment_studies')->where('machine_id', $extra->id)->count();
    if ($used > 0) {
        echo "  AVISO: no se elimina {$extra->name} ({$extra->id}): {$used} referencias\n";
        continue;
    }
    $extra->delete();
    echo "  Eliminada extra: {$extra->name}\n";
}

$dedupe = app(CatalogDedupeService::class);
$eStats = $dedupe->dedupeByLabAndName(\App\Models\Exam::class, false);

echo "\nDedupe exámenes: {$eStats['removed']} eliminados, {$eStats['reassigned']} reasignados\n";

$count = Machine::where('laboratory_id', $labId)->where('is_active', true)->count();
$exams = \App\Models\Exam::where('laboratory_id', $labId)->where('is_active', true)->count();
echo "\nResultado nube lab Siresa: {$count} máquinas, {$exams} exámenes activos\n";
