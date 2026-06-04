<?php

$root = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ap = App\Models\Appointment::with('patient.persona')->find($argv[1] ?? '');
if (!$ap?->patient) {
    exit(1);
}
$p = $ap->patient->toArray();
$p['persona'] = $ap->patient->persona->toArray();
$out = $argv[2] ?? '';
$json = json_encode($p);
if ($out !== '') {
    file_put_contents($out, $json);
    echo "written {$out}\n";
} else {
    echo $json;
}
