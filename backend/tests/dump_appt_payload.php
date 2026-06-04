<?php

$root = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = $argv[1] ?? '';
$a = App\Models\Appointment::with(['patient.persona', 'studies'])->find($id);
if (!$a) {
    exit(1);
}
$d = $a->toArray();
echo 'keys: ' . implode(', ', array_keys($d)) . PHP_EOL;
echo 'has patient: ' . (isset($d['patient']) ? 'yes' : 'no') . PHP_EOL;
if (isset($d['patient'])) {
    echo 'patient keys: ' . implode(', ', array_keys($d['patient'])) . PHP_EOL;
    echo 'persona rut: ' . ($d['patient']['persona']['rut'] ?? 'none') . PHP_EOL;
}
