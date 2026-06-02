<?php

$root = __DIR__;
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = $argv[1] ?? '';
$n = Illuminate\Support\Facades\DB::table('patients')->where('id', $id)->count();
echo "count={$n}\n";
