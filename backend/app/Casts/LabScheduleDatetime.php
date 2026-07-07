<?php

namespace App\Casts;

use App\Support\LabTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Persiste instantes de agenda siempre en UTC.
 *
 * El frontend envía hora local sin offset; el servicio de agenda puede trabajar
 * en America/Santiago. Sin conversión explícita, Eloquent guardaría la hora mural
 * como UTC (13:15 → se muestra 09:15 en Chile).
 */
class LabScheduleDatetime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->copy()->utc()->format('Y-m-d H:i:s');
        }

        return LabTimezone::parseScheduleTime((string) $value)->format('Y-m-d H:i:s');
    }
}
