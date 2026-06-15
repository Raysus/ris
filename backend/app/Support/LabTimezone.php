<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Zona horaria del centro (agenda + MWL).
 *
 * El frontend envía horarios locales sin offset (ej. 2026-06-08T11:15:00).
 * Laravel corre en UTC: hay que interpretar esos valores en la TZ del lab,
 * no como UTC, para que el .wl lleve la hora de la cita y no la UTC (p. ej. 21:00).
 */
class LabTimezone
{
    public static function name(): string
    {
        foreach ([
            config('app.worklist_timezone'),
            config('app.lab_timezone'),
        ] as $candidate) {
            $tz = trim((string) $candidate);
            if ($tz !== '') {
                return $tz;
            }
        }

        return 'America/Santiago';
    }

    /**
     * Interpreta un instante de agenda y lo devuelve en UTC para persistir en BD.
     */
    public static function parseScheduleTime(string $value): Carbon
    {
        $normalized = trim(str_replace(' ', 'T', $value));

        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $normalized) === 1) {
            return Carbon::parse($normalized)->utc();
        }

        return Carbon::parse($normalized, self::name())->utc();
    }

    /**
     * Hora de agenda para respuestas JSON (sin offset).
     * El frontend y FullCalendar interpretan estos valores como hora local del centro.
     */
    public static function formatScheduleForApi(Carbon|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $instant = $value instanceof Carbon
            ? $value->copy()
            : Carbon::parse($value);

        return $instant->timezone(self::name())->format('Y-m-d\TH:i:s');
    }

    /**
     * @return array{date: string, time: string}
     */
    public static function worklistDateTime(Carbon|string $startTime): array
    {
        $instant = $startTime instanceof Carbon
            ? $startTime->copy()
            : Carbon::parse($startTime);

        $local = $instant->timezone(self::name());

        return [
            'date' => $local->format('Ymd'),
            'time' => $local->format('His'),
        ];
    }
}
