<?php

$root = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = $argv[1] ?? '';
$type = $argv[2] ?? 'patient';
if ($type === 'persona') {
    $row = str_contains($id, '-') && strlen($id) > 20
        ? App\Models\Persona::find($id)
        : App\Models\Persona::findByRut($id);
    echo $row ? "persona found id={$row->id} rut={$row->rut}\n" : "persona missing\n";
    exit(0);
}
$plain = App\Models\Paciente::withoutGlobalScopes()->find($id);
echo $plain ? "found (lab={$plain->laboratory_id})\n" : "missing\n";
