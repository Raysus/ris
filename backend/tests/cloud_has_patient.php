<?php

$root = is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = $argv[1] ?? '';
$p = App\Models\Paciente::find($id);
echo $p ? "LOCAL ok persona={$p->persona_id}\n" : "LOCAL missing\n";

$cloudUrl = rtrim(config('cloud_sync.inbound_url'), '/');
$secret = config('cloud_sync.secret');
$r = Illuminate\Support\Facades\Http::timeout(15)->withToken($secret)
    ->get(str_replace('/inbound', '/export', $cloudUrl), ['include_patients' => 'true', 'laboratory_id' => $p?->laboratory_id]);
$ids = collect($r->json('data.patients') ?? [])->pluck('id')->all();
echo in_array($id, $ids, true) ? "CLOUD export lists patient\n" : "CLOUD export missing patient (http {$r->status()})\n";
