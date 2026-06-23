<?php

$backend = is_file(__DIR__ . '/../vendor/autoload.php')
    ? dirname(__DIR__)
    : getcwd();

require $backend . '/vendor/autoload.php';
$app = require $backend . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Exam;
use App\Models\Laboratory;
use App\Services\ExamSubExamService;

/** Prestaciones ECOTEMUCO (presta FONASA → precio bene3). */
const ECOTEMUCO_EXAM_PRICES = [
    '0404003' => 30320,
    '0404006' => 16130,
    '0404009' => 16860,
    '0404010' => 21020,
    '0404011' => 22690,
    '0404012' => 21130,
    '0404014' => 20860,
    '0404015' => 21130,
    '0404016' => 21130,
    '0404118' => 69340,
    '0404119' => 65480,
    '0404121' => 71460,
];

function cleanSourceExamName(string $name, string $fonasaCode): string
{
    $name = trim($name);

    if ($fonasaCode === '0404003' && str_contains($name, ' 04 04 004')) {
        $name = explode(' 04 04 004', $name)[0];
        $name = rtrim($name, " -\t");
    }

    return $name;
}

$targetLab = Laboratory::query()
    ->whereNull('parent_id')
    ->where('name', 'ECOTEMUCO')
    ->first();

if (!$targetLab) {
    fwrite(STDERR, "No se encontró la casa matriz ECOTEMUCO.\n");
    exit(1);
}

$sourceLab = Laboratory::query()
    ->whereNull('parent_id')
    ->where('name', 'Siresa')
    ->first();

if (!$sourceLab) {
    fwrite(STDERR, "No se encontró Siresa como fuente de exámenes.\n");
    exit(1);
}

echo "Fuente: {$sourceLab->name} ({$sourceLab->id})\n";
echo "Destino: {$targetLab->name} ({$targetLab->id})\n";

$created = 0;
$updated = 0;

foreach (ECOTEMUCO_EXAM_PRICES as $fonasaCode => $price) {
    $source = Exam::query()
        ->where('laboratory_id', $sourceLab->id)
        ->where('fonasa_code', $fonasaCode)
        ->with(['subExams', 'instruction'])
        ->orderBy('created_at')
        ->first();

    if (!$source) {
        echo "  [omitido] {$fonasaCode}: no existe en Siresa\n";
        continue;
    }

    $name = cleanSourceExamName($source->name, $fonasaCode);

    $exam = Exam::query()
        ->where('laboratory_id', $targetLab->id)
        ->where('fonasa_code', $fonasaCode)
        ->first();

    $isNew = !$exam;
    if ($isNew) {
        $exam = new Exam();
        $exam->laboratory_id = $targetLab->id;
    }

    $exam->group_code = $source->group_code ?: 'US';
    $exam->name = $name;
    $exam->fonasa_code = $fonasaCode;
    $exam->price = $price;
    $exam->estimated_duration = $source->estimated_duration;
    $exam->is_active = true;
    $exam->sub_exams = [];
    $exam->save();

    $subItems = $source->subExams->isNotEmpty()
        ? $source->subExams->map(fn ($s) => [
            'name' => $s->name,
            'fonasa_code' => $s->fonasa_code,
            'additional_price' => $s->additional_price,
        ])->all()
        : ExamSubExamService::serializeForAgenda($source);

    ExamSubExamService::syncFromItems($exam, $subItems);

    if ($source->instruction) {
        $exam->instruction()->updateOrCreate(
            ['exam_id' => $exam->id],
            [
                'subject' => $source->instruction->subject,
                'body' => $source->instruction->body,
                'is_active' => $source->instruction->is_active,
            ]
        );
    }

    echo ($isNew ? '  [nuevo] ' : '  [actualizado] ')
        . "{$fonasaCode} | {$name} | \${$price} | subs="
        . $exam->subExams()->count() . "\n";

    $isNew ? $created++ : $updated++;
}

echo "Listo: {$created} creados, {$updated} actualizados.\n";
