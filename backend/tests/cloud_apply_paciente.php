<?php

$root = __DIR__;
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$data = json_decode(file_get_contents($argv[1] ?? 'php://stdin'), true);
if (!$data) {
    fwrite(STDERR, "JSON requerido\n");
    exit(1);
}

try {
    $rut = $data['persona']['rut'] ?? '';
    $personaId = App\Models\Persona::findByRut((string) $rut)?->id;
    echo "personaByRut={$personaId}\n";
    (new App\Services\CloudEntitySyncService())->apply('Paciente', 'updated', $data);
    $id = $data['id'] ?? '';
    $n = Illuminate\Support\Facades\DB::table('patients')->where('id', $id)->count();
    echo $n ? "ok count={$n}\n" : "apply ok but count=0\n";
    if ($n === 0 && $personaId) {
        Illuminate\Support\Facades\DB::table('patients')->insert([
            'id' => $id,
            'persona_id' => $personaId,
            'laboratory_id' => $data['laboratory_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        echo 'raw insert count=' . Illuminate\Support\Facades\DB::table('patients')->where('id', $id)->count() . "\n";
    }
} catch (Throwable $e) {
    echo "ERR: {$e->getMessage()}\n";
    exit(1);
}
