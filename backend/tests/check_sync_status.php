<?php

$root = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = $argv[1] ?? '';
$log = App\Models\CloudSyncLog::where('entity_id', $id)->orderByDesc('created_at')->first();
echo $log ? "{$log->status}\n{$log->last_error}\n" : "no log\n";
