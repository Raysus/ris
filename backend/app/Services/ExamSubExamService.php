<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\SubExam;

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
            '0401060' => [
                'Hombro derecho frontal y lateral',
                'Hombro izquierdo frontal y lateral',
                'Fémur derecho frontal y lateral',
                'Fémur izquierdo frontal y lateral',
                'Rodilla derecha frontal y lateral',
                'Rodilla izquierda frontal y lateral',
                'Pierna derecha frontal y lateral',
                'Pierna izquierda frontal y lateral',
                'Costilla derecha frontal y lateral',
                'Costilla izquierda frontal y lateral',
                'Esternón frontal y lateral',
                'Escápula derecha',
                'Escápula izquierda',
            ],
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
            '0401045' => [
                'Columna dorsal frontal y lateral',
                'Parrilla costal derecha',
                'Parrilla costal izquierda',
            ],
            '0401042' => [
                'Columna cervical frontal',
                'Columna cervical lateral',
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
            $exam->subExams()->whereNotIn('id', $keepIds)->delete();
        } else {
            $exam->subExams()->delete();
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
