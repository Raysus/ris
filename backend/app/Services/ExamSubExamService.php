<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\SubExam;
use Illuminate\Support\Facades\DB;

class ExamSubExamService
{
    /**
     * Variantes habituales en RIS/FONASA (mismo código, distinta prestación clínica).
     *
     * @return array<string, list<string>>
     */
    public static function predefinedVariantsByFonasaCode(): array
    {
        return [
            '0401031' => [
                'Cavidades perinasales',
                'Órbitas',
                'ATM derecho',
                'ATM izquierdo',
                'Maxilar',
                'Cara',
                'Malar derecho',
                'Malar izquierdo',
                'Arco cigomático derecho',
                'Arco cigomático izquierdo',
            ],
            '0401035' => [
                'Oído derecho',
                'Oído izquierdo',
                'Ambos oídos',
            ],
            '0401042' => [
                'Columna cervical frontal y lateral',
                'Atlas-axis frontal y lateral',
            ],
            '0401043' => [
                'Columna cervical oblicua derecha',
                'Columna cervical oblicua izquierda',
            ],
            '0401044' => [
                'Columna cervical dinámica flexión',
                'Columna cervical dinámica extensión',
            ],
            '0401045' => [
                'Columna dorsal frontal y lateral',
                'Parrilla costal derecha',
                'Parrilla costal izquierda',
            ],
            '0401047' => [
                'Columna lumbar flexión',
                'Columna lumbar extensión',
            ],
            '0401048' => [
                'Columna lumbar oblicua derecha',
                'Columna lumbar oblicua izquierda',
            ],
            '0401051' => [
                'Pelvis AP',
                'Pelvis frontal y lateral',
                'Cadera derecha frontal y lateral',
                'Cadera izquierda frontal y lateral',
                'Caderas ambas frontal y lateral',
                'Coxofemoral derecha',
                'Coxofemoral izquierda',
                'Coxofemorales ambas',
            ],
            '0401052' => [
                'Rotación interna cadera derecha',
                'Rotación interna cadera izquierda',
                'Abducción cadera derecha',
                'Abducción cadera izquierda',
                'Proyección lateral cadera derecha',
                'Proyección lateral cadera izquierda',
                'Lawenstein cadera derecha',
                'Lawenstein cadera izquierda',
            ],
            '0401053' => [
                'Sacro-coxis AP y lateral',
                'Articulación sacroilíaca derecha',
                'Articulación sacroilíaca izquierda',
            ],
            '0401054' => [
                'Brazo derecho frontal y lateral',
                'Brazo izquierdo frontal y lateral',
                'Brazos ambos frontal y lateral',
                'Antebrazo derecho frontal y lateral',
                'Antebrazo izquierdo frontal y lateral',
                'Antebrazos ambos frontal y lateral',
                'Codo derecho frontal y lateral',
                'Codo izquierdo frontal y lateral',
                'Codos ambos frontal y lateral',
                'Muñeca derecha frontal y lateral',
                'Muñeca izquierda frontal y lateral',
                'Muñecas ambas frontal y lateral',
                'Mano derecha frontal y lateral',
                'Mano izquierda frontal y lateral',
                'Manos ambas frontal y lateral',
                'Dedos mano derecha',
                'Dedos mano izquierda',
                'Dedos ambas manos',
                'Pie derecho frontal y lateral',
                'Pie izquierdo frontal y lateral',
                'Pies ambos frontal y lateral',
            ],
            '0401055' => [
                'Clavícula derecha',
                'Clavícula izquierda',
                'Clavículas ambas',
            ],
            '0401059' => [
                'Muñeca derecha frontal, lateral y oblicuas',
                'Muñeca izquierda frontal, lateral y oblicuas',
                'Muñecas ambas frontal, lateral y oblicuas',
                'Tobillo derecho frontal, lateral y oblicuas',
                'Tobillo izquierdo frontal, lateral y oblicuas',
                'Tobillos ambos frontal, lateral y oblicuas',
            ],
            '0401060' => [
                'Hombro derecho frontal y lateral',
                'Hombro izquierdo frontal y lateral',
                'Hombros ambos frontal y lateral',
                'Fémur derecho frontal y lateral',
                'Fémur izquierdo frontal y lateral',
                'Fémures ambos frontal y lateral',
                'Rodilla derecha frontal y lateral',
                'Rodilla izquierda frontal y lateral',
                'Rodillas ambas frontal y lateral',
                'Pierna derecha frontal y lateral',
                'Pierna izquierda frontal y lateral',
                'Piernas ambas frontal y lateral',
                'Costilla derecha frontal y lateral',
                'Costilla izquierda frontal y lateral',
                'Costillas ambas frontal y lateral',
                'Esternón frontal y lateral',
                'Escápula derecha',
                'Escápula izquierda',
                'Escápulas ambas',
            ],
            '0401062' => [
                'Hombro derecho — proyección oblicua',
                'Hombro izquierdo — proyección oblicua',
                'Hombros ambos — proyección oblicua',
                'Brazo derecho — proyección oblicua',
                'Brazo izquierdo — proyección oblicua',
                'Brazos ambos — proyección oblicua',
                'Codo derecho — proyección oblicua',
                'Codo izquierdo — proyección oblicua',
                'Codos ambos — proyección oblicua',
                'Rodilla derecha — proyección oblicua',
                'Rodilla izquierda — proyección oblicua',
                'Rodillas ambas — proyección oblicua',
                'Rótula derecha — axial',
                'Rótula izquierda — axial',
                'Axial de ambas rótulas',
                'Sesamoideo pie derecho',
                'Sesamoideo pie izquierdo',
                'Sesamoides ambos pies',
            ],
            '0401063' => [
                'Radio-carpiano derecho',
                'Radio-carpiano izquierdo',
                'Radio-carpianos ambos',
                'Túnel intercondíleo rodilla derecha',
                'Túnel intercondíleo rodilla izquierda',
                'Túnel intercondíleo ambas rodillas',
            ],
            '0401130' => [
                'Proyección axilar derecha',
                'Proyección axilar izquierda',
                'Proyecciones axilares ambas',
                'Magnificación',
                'Otra proyección complementaria',
            ],
            '0401151' => [
                'Pelvis AP',
                'Cadera derecha',
                'Cadera izquierda',
                'Caderas ambas',
            ],
            '0404005' => [
                'Ecografía transvaginal',
                'Ecografía transrectal',
            ],
            '0404010' => [
                'Ecografía renal bilateral',
                'Ecografía de bazo',
            ],
            '0404013' => [
                'Ecografía ocular derecha',
                'Ecografía ocular izquierda',
                'Ecografía ocular bilateral',
            ],
            '0404014' => [
                'Ecografía testicular derecha',
                'Ecografía testicular izquierda',
                'Ecografía testicular bilateral',
            ],
            '0404016' => [
                'Hombro',
                'Codo',
                'Muñeca',
                'Mano',
                'Cadera',
                'Muslo',
                'Rodilla',
                'Pierna',
                'Tobillo',
                'Pie',
                'Espalda / región dorsal',
                'Región axilar',
                'Región inguinal',
            ],
            '0407020' => [
                'Densitometría columna lumbar',
                'Densitometría cadera',
                'Densitometría extremidades',
            ],
        ];
    }

    /**
     * @param  list<mixed>  $items  Nombres (string) u objetos con clave name
     */
    public static function syncFromItems(Exam $exam, array $items): void
    {
        $names = self::extractNames($items);
        $existing = $exam->subExams()->get()->keyBy(
            fn (SubExam $s) => mb_strtolower(trim($s->name))
        );

        $keepIds = [];
        foreach ($names as $name) {
            $key = mb_strtolower($name);
            if ($existing->has($key)) {
                $sub = $existing->get($key);
                if ($sub->fonasa_code !== $exam->fonasa_code) {
                    $sub->fonasa_code = $exam->fonasa_code;
                    $sub->saveQuietly();
                }
                $keepIds[] = $sub->id;
                continue;
            }

            $sub = $exam->subExams()->create([
                'name' => $name,
                'fonasa_code' => $exam->fonasa_code,
                'additional_price' => 0,
            ]);
            $keepIds[] = $sub->id;
        }

        if ($keepIds !== []) {
            $toDelete = $exam->subExams()->whereNotIn('id', $keepIds)->pluck('id');
        } else {
            $toDelete = $exam->subExams()->pluck('id');
        }

        if ($toDelete->isNotEmpty()) {
            DB::table('appointment_studies')
                ->whereIn('sub_exam_id', $toDelete)
                ->update(['sub_exam_id' => null]);

            $exam->subExams()->whereIn('id', $toDelete)->delete();
        }

        // Evita conflicto JSON vs relación en serialización de agenda
        if (! empty($exam->getAttributes()['sub_exams'] ?? null)) {
            $exam->forceFill(['sub_exams' => []])->saveQuietly();
        }
    }

    /**
     * @param  list<mixed>  $items
     * @return list<string>
     */
    public static function extractNames(array $items): array
    {
        $names = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $name = trim($item);
            } elseif (is_array($item) && ! empty($item['name'])) {
                $name = trim((string) $item['name']);
            } else {
                continue;
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<int, array{id: string, name: string, fonasa_code: string|null, additional_price: int}>
     */
    public static function serializeForAgenda(Exam $exam): array
    {
        $exam->loadMissing('subExams');

        if ($exam->subExams->isNotEmpty()) {
            return $exam->subExams->map(fn (SubExam $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'fonasa_code' => $s->fonasa_code,
                'additional_price' => (int) $s->additional_price,
            ])->values()->all();
        }

        $json = $exam->getAttributes()['sub_exams'] ?? null;
        if (! is_array($json) || $json === []) {
            return [];
        }

        return collect($json)->map(function ($item) use ($exam) {
            if (is_string($item) && trim($item) !== '') {
                return [
                    'id' => null,
                    'name' => trim($item),
                    'fonasa_code' => $exam->fonasa_code,
                    'additional_price' => 0,
                ];
            }
            if (is_array($item) && ! empty($item['name'])) {
                return [
                    'id' => $item['id'] ?? null,
                    'name' => (string) $item['name'],
                    'fonasa_code' => $item['fonasa_code'] ?? $exam->fonasa_code,
                    'additional_price' => (int) ($item['additional_price'] ?? 0),
                ];
            }

            return null;
        })->filter()->values()->all();
    }

    public static function applyPredefinedVariants(?string $laboratoryId = null): int
    {
        $updated = 0;
        $query = Exam::query()->where('is_active', true);

        if ($laboratoryId) {
            $query->where('laboratory_id', $laboratoryId);
        }

        foreach (self::predefinedVariantsByFonasaCode() as $code => $names) {
            $exams = (clone $query)->where('fonasa_code', $code)->get();
            foreach ($exams as $exam) {
                self::syncFromItems($exam, $names);
                $updated++;
            }
        }

        return $updated;
    }
}
