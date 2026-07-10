<?php

/**
 * Actualiza precios Siresa según lista VALORES 2026 (FONASA + PARTICULAR).
 *
 * - exams.price         = Particular
 * - exams.fonasa_price  = FONASA
 * - tariffs             = mismo criterio por plan (Fonasa / Particular)
 *
 * Uso (API container):
 *   php scripts/update-siresa-prices-2026.php
 */

$backend = is_file(__DIR__ . '/../vendor/autoload.php')
    ? dirname(__DIR__)
    : '/var/www/html';

require $backend . '/vendor/autoload.php';
$app = require $backend . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Exam;
use App\Models\InsurancePlan;
use App\Models\Laboratory;
use App\Models\Tariff;
use Illuminate\Support\Str;

/**
 * Lista impresa VALORES 2026.
 * match: fonasa_code preferente; name_contains / name_equals como respaldo o alta.
 */
const SIRESA_PRICES_2026 = [
    [
        'label' => 'DENSITOMETRIA',
        'fonasa' => 41930,
        'particular' => 55000,
        'group' => 'DEXA',
        'fonasa_code' => '0501130',
        'name_contains' => ['DENSITOMET'],
        'create_name' => 'DENSITOMETRÍA ÓSEA DE COLUMNA LUMBAR, O DE CADERA, O DE EXTREMIDADES',
    ],
    [
        'label' => 'MAMOGRAFIA',
        'fonasa' => 25850,
        'particular' => 40000,
        'group' => 'MAMO',
        'fonasa_code' => '0401010',
        'name_contains' => ['MAMOGRAFIA BILATERAL', 'MAMOGRAFÍA BILATERAL'],
    ],
    [
        'label' => 'ECO ABDOMINAL',
        'fonasa' => 30320,
        'particular' => 50000,
        'group' => 'US',
        'fonasa_code' => '0404003',
        'name_contains' => ['ECO ABDOMINAL'],
    ],
    [
        'label' => 'ECO PELVIANA FEM.',
        'fonasa' => 16130,
        'particular' => 45000,
        'group' => 'US',
        'fonasa_code' => '0404006',
        'name_contains' => ['PELVIANA FEM', 'GINECOLOGICA', 'GINECOLÓGICA'],
    ],
    [
        'label' => 'ECO PELVIANA MASC.',
        'fonasa' => 16860,
        'particular' => 45000,
        'group' => 'US',
        'fonasa_code' => '0404009',
        'name_contains' => ['PELVICA MASC', 'PÉLVICA MASC', 'PELVIANA MASC'],
    ],
    [
        'label' => 'ECO P. BLANDAS',
        'fonasa' => 21130,
        'particular' => 45000,
        'group' => 'US',
        'fonasa_code' => '0404016',
        'name_contains' => ['PARTES BLANDAS', 'P. BLANDAS'],
    ],
    [
        'label' => 'ECO TESTICULAR',
        'fonasa' => 20860,
        'particular' => 45000,
        'group' => 'US',
        'fonasa_code' => '0404014',
        'name_contains' => ['ECO TESTICULAR'],
    ],
    [
        'label' => 'ECO RENAL',
        'fonasa' => 21020,
        'particular' => 45000,
        'group' => 'US',
        'fonasa_code' => '0404010',
        'name_contains' => ['ECO RENAL'],
    ],
    [
        'label' => 'ECO DOPPLER VEN/ART',
        'fonasa' => 69340,
        'particular' => 150000,
        'group' => 'US',
        'fonasa_code' => '0404118',
        'name_contains' => ['VASCULAR ARTERIAL', 'DOPPLER VEN', 'ARTERIAL O VENOSA'],
    ],
    [
        'label' => 'ECO DOPPLER CAROTIDEO',
        'fonasa' => 65480,
        'particular' => 150000,
        'group' => 'US',
        'fonasa_code' => '0404119',
        'name_contains' => ['CAROTID', 'DOPPLER TIROIDEA'],
        'rename_to' => 'ECO DOPPLER CAROTÍDEO',
    ],
    [
        'label' => 'ECO DOPPLER TESTICULAR',
        'fonasa' => 71460,
        'particular' => 150000,
        'group' => 'US',
        'fonasa_code' => '0404121',
        'name_contains' => ['DOPPLER TESTICULAR'],
        'create_name' => 'ECO DOPPLER TESTICULAR',
    ],
    [
        'label' => 'RX COLUMNA TOTAL AP/LAT',
        'fonasa' => 31340,
        'particular' => 60600,
        'group' => 'RX',
        'fonasa_code' => '0401049',
        'name_contains' => ['COLUMNA TOTAL'],
    ],
    [
        'label' => 'RX TÓRAX AP',
        'fonasa' => 12150,
        'particular' => 16500,
        'group' => 'RX',
        'fonasa_code' => '0401009',
        'name_contains' => ['TORAX SIMPLE', 'TÓRAX SIMPLE'],
    ],
    [
        'label' => 'RX TÓRAX AP/LAT',
        'fonasa' => 21920,
        'particular' => 33000,
        'group' => 'RX',
        'fonasa_code' => '0401070',
        'name_contains' => ['TORAX FRONTAL Y LATERAL', 'TÓRAX FRONTAL Y LATERAL'],
    ],
    [
        'label' => 'RX COL. LUMBAR',
        'fonasa' => 20950,
        'particular' => 27000,
        'group' => 'RX',
        'fonasa_code' => '0401046',
        'name_contains' => ['COLUMNA LUMBOSACRA (', 'COL LUMBOSACRA ('],
        'name_excludes' => ['OBLICUA', 'DINAMICA', 'DINÁMICA', 'FLEXION'],
    ],
    [
        'label' => 'RX LUMBAR OBLICUA',
        'fonasa' => 11470,
        'particular' => 16000,
        'group' => 'RX',
        'fonasa_code' => '0401048',
        'name_contains' => ['LUMBOSACRA OBLICUA'],
    ],
    [
        'label' => 'RX LUMBAR DINÁMICA',
        'fonasa' => 34060,
        'particular' => 35000,
        'group' => 'RX',
        'fonasa_code' => '0401047',
        'name_contains' => ['LUMBOSACRA FLEXION', 'LUMBOSACRA FLEXIÓN', 'LUMBAR DINAM'],
    ],
    [
        'label' => 'RX CERVICAL',
        'fonasa' => 12150,
        'particular' => 15000,
        'group' => 'RX',
        'fonasa_code' => '0401042',
        'name_contains' => ['COL CERVICAL O ATLAS', 'CERVICAL O ATLAS'],
    ],
    [
        'label' => 'RX CERVICAL AP/LAT Y OBL.',
        'fonasa' => 20460,
        'particular' => 28000,
        'group' => 'RX',
        'fonasa_code' => '0401043',
        'name_contains' => ['COL CERVICAL (FRONTAL', 'CERVICAL (FRONTAL'],
    ],
    [
        'label' => 'RX DORSAL',
        'fonasa' => 14150,
        'particular' => 21000,
        'group' => 'RX',
        'fonasa_code' => '0401045',
        'name_contains' => ['COLUMNA DORSAL', 'DORSOLUMBAR'],
    ],
    [
        'label' => 'RX PELVIS AP',
        'fonasa' => 9310,
        'particular' => 15600,
        'group' => 'RX',
        'fonasa_code' => '0401051',
        'name_contains' => ['RX PELVIS O CADERA'],
        'name_excludes' => ['RN', 'ESPECIAL'],
    ],
    [
        'label' => 'RX PELVIS LAT U OBLICUA',
        'fonasa' => 8490,
        'particular' => 15600,
        'group' => 'RX',
        'fonasa_code' => '0401052',
        'name_contains' => ['PROYECCIONES ESPECIALES DE CADERA', 'PELVIS LAT'],
    ],
    [
        'label' => 'RX CAVUM',
        'fonasa' => 10700,
        'particular' => 18000,
        'group' => 'RX',
        'fonasa_code' => '0401002',
        'name_contains' => ['CAVUM'],
    ],
    [
        'label' => 'RX CAVIDADES PERINASALES',
        'fonasa' => 12060,
        'particular' => 17000,
        'group' => 'RX',
        'fonasa_code' => '0401031',
        'name_contains' => ['CAVIDADES PERINASALES', 'PERINASALES'],
    ],
    [
        'label' => 'RX EDAD OSEA',
        'fonasa' => 8840,
        'particular' => 16000,
        'group' => 'RX',
        'fonasa_code' => '0401056',
        'name_contains' => ['EDAD OSEA', 'EDAD ÓSEA'],
    ],
    [
        'label' => 'RX CRANEO',
        'fonasa' => 12610,
        'particular' => 17000,
        'group' => 'RX',
        'fonasa_code' => '0401032',
        'name_contains' => ['CRANEO FRONTAL', 'CRÁNEO FRONTAL'],
    ],
    [
        'label' => 'RX SACROCOXIS',
        'fonasa' => 12780,
        'particular' => 17000,
        'group' => 'RX',
        'fonasa_code' => '0401053',
        'name_contains' => ['SACROCOXIS'],
        'rename_to' => 'RX SACROCOXIS',
    ],
    [
        'label' => 'RX SACROILIACAS',
        'fonasa' => 25560,
        'particular' => 34000,
        'group' => 'RX',
        'fonasa_code' => null,
        'name_contains' => ['SACROILIAC', 'SACROILÍAC'],
        'name_excludes' => ['SACROCOXIS'],
        'create_name' => 'RX SACROILIACAS',
    ],
    [
        'label' => 'RX MANO/PIE',
        'fonasa' => 10600,
        'particular' => 16000,
        'group' => 'RX',
        'fonasa_code' => '0401054',
        'name_contains' => ['MANO', 'PIE (FRONTAL'],
    ],
    [
        'label' => 'RX HOMBRO/RODILLA',
        'fonasa' => 12540,
        'particular' => 21000,
        'group' => 'RX',
        'fonasa_code' => '0401060',
        'name_contains' => ['HOMBRO', 'RODILLA'],
        'name_excludes' => ['OBLICUA', 'AXIAL', 'ESPECIALES OBLICUAS'],
    ],
    [
        'label' => 'RX AXIALES U OBLICUA',
        'fonasa' => 8720,
        'particular' => 16000,
        'group' => 'RX',
        'fonasa_code' => '0401062',
        'name_contains' => ['OBLICUAS U OTRAS', 'AXIAL DE ROTULA', 'AXIALES'],
    ],
    [
        'label' => 'ELECTROCARDIOGRAMA',
        'fonasa' => 8380,
        'particular' => 25000,
        'group' => 'ECG',
        'fonasa_code' => '1701001',
        'name_contains' => ['ELECTROCARDIOGRAMA', 'ECG'],
        'create_name' => 'ELECTROCARDIOGRAMA',
    ],
];

function upsertTariff(string $examId, string $planId, float $price): void
{
    Tariff::updateOrCreate(
        ['exam_id' => $examId, 'insurance_plan_id' => $planId],
        ['price' => $price, 'copay' => 0]
    );
}

function normalize(string $value): string
{
    $value = Str::upper($value);
    $value = strtr($value, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        'Ü' => 'U',
    ]);

    return preg_replace('/\s+/', ' ', trim($value)) ?? '';
}

function findExam(string $labId, array $row): ?Exam
{
    $query = Exam::query()->where('laboratory_id', $labId);

    if (!empty($row['fonasa_code']) && !str_contains((string) $row['fonasa_code'], '-')) {
        $byCode = (clone $query)->where('fonasa_code', $row['fonasa_code'])->orderBy('created_at')->first();
        if ($byCode) {
            return $byCode;
        }
    }

    $candidates = (clone $query)->orderBy('name')->get();
    foreach ($candidates as $exam) {
        $name = normalize($exam->name);
        $ok = false;
        foreach ($row['name_contains'] ?? [] as $needle) {
            if (str_contains($name, normalize($needle))) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            continue;
        }
        foreach ($row['name_excludes'] ?? [] as $ex) {
            if (str_contains($name, normalize($ex))) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return $exam;
        }
    }

    return null;
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
$missing = [];

foreach (SIRESA_PRICES_2026 as $row) {
    $exam = findExam((string) $lab->id, $row);

    if (!$exam && !empty($row['create_name'])) {
        $exam = Exam::create([
            'laboratory_id' => $lab->id,
            'group_code' => $row['group'],
            'name' => $row['create_name'],
            'fonasa_code' => $row['fonasa_code'] ?: null,
            'price' => $row['particular'],
            'fonasa_price' => $row['fonasa'],
            'estimated_duration' => 20,
            'is_active' => true,
            'sub_exams' => [],
        ]);
        $created++;
        echo "  [nuevo] {$row['label']} → {$exam->name}\n";
    } elseif (!$exam) {
        $missing[] = $row['label'];
        echo "  [omitido] {$row['label']}: examen no encontrado\n";
        continue;
    } else {
        $exam->price = $row['particular'];
        $exam->fonasa_price = $row['fonasa'];
        if (!empty($row['rename_to']) && normalize($exam->name) !== normalize($row['rename_to'])) {
            echo "  [rename] {$exam->name} → {$row['rename_to']}\n";
            $exam->name = $row['rename_to'];
        }
        if (!empty($row['fonasa_code']) && !str_contains((string) $row['fonasa_code'], '-')) {
            $exam->fonasa_code = $row['fonasa_code'];
        }
        if (!empty($row['group'])) {
            $exam->group_code = $row['group'];
        }
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
        "  OK %-28s | Fonasa $%s | Particular $%s | %s\n",
        $row['label'],
        number_format($row['fonasa'], 0, ',', '.'),
        number_format($row['particular'], 0, ',', '.'),
        Str::limit($exam->name, 50)
    );
}

echo "\nListo: {$updated} actualizados, {$created} creados, {$tariffs} tarifas.\n";
if ($missing) {
    echo 'Sin match: ' . implode(', ', $missing) . "\n";
    exit(2);
}
