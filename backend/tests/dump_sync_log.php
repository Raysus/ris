<?php

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = $argv[1] ?? null;
$log = App\Models\CloudSyncLog::where('entity_id', $id)->orWhere('payload->id', $id)->orderByDesc('created_at')->first();
if (!$log) {
    echo "no log\n";
    exit(1);
}
$keys = array_keys($log->payload ?? []);
echo "entity_type={$log->entity_type} status={$log->status}\n";
echo 'payload keys: ' . implode(', ', $keys) . "\n";
echo 'has patient: ' . (isset($log->payload['patient']) ? 'yes' : 'no') . "\n";
if (isset($log->payload['patient'])) {
    echo 'patient keys: ' . implode(', ', array_keys($log->payload['patient'])) . "\n";
}
