<?php

/**
 * Actualiza precios Siresa 2026 (Fonasa y Particular).
 * - exams.price = arancel Fonasa (mismo valor todos los tramos/planes Fonasa)
 * - tariffs = precio Particular para planes Particular; Fonasa para planes Fonasa
 *
 * Uso: php scripts/update-siresa-prices-2026.php
 */

$backend = is_file(__DIR__ . '/../vendor/autoload.php')
    ? dirname(__DIR__)
    : '/var/www/html';

require $backend . '/vendor/autoload.php';
$app = require $backend . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Exam;
use App\Models\Insurance;
use App\Models\InsurancePlan;
use App\Models\Laboratory;
use App\Models\Tariff;
use Illuminate\Support\Str;

/** Valores arancel Fonasa 2026 — todos los tramos A/B/C/D comparten el mismo arancel. */
const SIRESA_PRICES_2026 = [
    '0404003' => ['fonasa' => 30320, 'particular' => 50000, 'group' => 'US'],
    '0404006' => ['fonasa' => 16130, 'particular' => 45000, 'group' => 'US'],
    '0404009' => ['fonasa' => 16860, 'particular' => 45000, 'group' => 'US'],
    '0404010' => ['fonasa' => 21020, 'particular' => 45000, 'group' => 'US'],
    '0404012' => ['fonasa' => 21130, 'particular' => 45000, 'group' => 'US'],
    '0404014' => ['fonasa' => 20860, 'particular' => 45000, 'group' => 'US'],
    '0404015' => ['fonasa' => 21130, 'particular' => 45000, 'group' => 'US'],
    '0404016' => ['fonasa' => 21130, 'particular' => 45000, 'group' => 'US'],
    '0401010' => ['fonasa' => 25850, 'particular' => 40000, 'group' => 'MAMO'],
    '0404118' => ['fonasa' => 69340, 'particular' => 150000, 'group' => 'US'],
    '0404119' => ['fonasa' => 65480, 'particular' => 130000, 'group' => 'US'],
    '0404121' => ['fonasa' => 71460, 'particular' => 150000, 'group' => 'US'],
    '0407020' => [
        'fonasa' => 41930,
        'particular' => 55000,
        'group' => 'DX',
        'name' => 'DENSITOMETRÍA ÓSEA DE COLUMNA LUMBAR, O DE CADERA, O DE EXTREMIDADES',
    ],
];

function upsertTariff(string $examId, string $planId, float $price): void
{
    Tariff::updateOrCreate(
        ['exam_id' => $examId, 'insurance_plan_id' => $planId],
        ['price' => $price, 'copay' => 0]
    );
}

$lab = Laboratory::query()->whereNull('parent_id')->where('name', 'Siresa')->first();
if (!$lab) {
    fwrite(STDERR, "No se encontró laboratorio Siresa.\n");
    exit(1);
}

$fonasaPlans = InsurancePlan::query()
    ->where('is_active', true)
    ->whereHas('insurance', fn ($q) => $q->where('name', 'ilike', 'fonasa'))
    ->get();

$particularPlans = InsurancePlan::query()
    ->where('is_active', true)
    ->whereHas('insurance', fn ($q) => $q->where('name', 'ilike', 'particular'))
    ->get();

echo "Lab Siresa: {$lab->id}\n";
echo "Planes Fonasa: {$fonasaPlans->count()} · Particular: {$particularPlans->count()}\n\n";

$updated = 0;
$created = 0;
$tariffs = 0;

foreach (SIRESA_PRICES_2026 as $code => $row) {
    $exam = Exam::query()
        ->where('laboratory_id', $lab->id)
        ->where('fonasa_code', $code)
        ->orderBy('created_at')
        ->first();

    if (!$exam && !empty($row['name'])) {
        $exam = Exam::create([
            'laboratory_id' => $lab->id,
            'group_code' => $row['group'],
            'name' => $row['name'],
            'fonasa_code' => $code,
            'price' => $row['fonasa'],
            'estimated_duration' => 20,
            'is_active' => true,
            'sub_exams' => [],
        ]);
        $created++;
        echo "  [nuevo] {$code} | {$exam->name}\n";
    } elseif (!$exam) {
        echo "  [omitido] {$code}: examen no encontrado\n";
        continue;
    } else {
        $exam->price = $row['fonasa'];
        $exam->save();
        $updated++;
    }

    foreach ($fonasaPlans as $plan) {
        upsertTariff((string) $exam->id, (string) $plan->id, (float) $row['fonasa']);
        $tariffs++;
    }
    foreach ($particularPlans as $plan) {
        upsertTariff((string) $exam->id, (string) $plan->id, (float) $row['particular']);
        $tariffs++;
    }

    echo sprintf(
        "  [%s] %s | Fonasa $%s | Particular $%s\n",
        $code,
        Str::limit($exam->name, 48),
        number_format($row['fonasa'], 0, ',', '.'),
        number_format($row['particular'], 0, ',', '.')
    );
}

echo "\nListo: {$updated} exámenes actualizados, {$created} creados, {$tariffs} tarifas upsert.\n";
