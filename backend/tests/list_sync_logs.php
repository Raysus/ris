<?php

$root = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$logs = App\Models\CloudSyncLog::orderByDesc('created_at')->limit(20)->get();
echo 'total recent: ' . $logs->count() . PHP_EOL;

foreach ($logs as $log) {
    echo "{$log->created_at} {$log->entity_type} {$log->status} " . substr((string) $log->last_error, 0, 100) . PHP_EOL;
}
