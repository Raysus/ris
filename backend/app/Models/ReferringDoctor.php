<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReferringDoctor extends Model
{
    use HasUuids;

    protected $fillable = ['rut', 'names', 'last_name_1', 'last_name_2', 'phone', 'email'];

    protected static function booted(): void
    {
        static::saving(function (ReferringDoctor $doctor) {
            if ($doctor->isDirty('rut') && filled($doctor->rut)) {
                $doctor->rut = self::normalizeRut($doctor->rut);
            }
        });
    }

    public static function normalizeRut(?string $rut): ?string
    {
        if (!filled($rut)) {
            return null;
        }

        return Persona::normalizeRut($rut);
    }

    public static function findByRut(?string $rut): ?self
    {
        $normalized = self::normalizeRut($rut);
        if (!$normalized) {
            return null;
        }

        return static::query()
            ->whereRaw(
                "UPPER(REPLACE(REPLACE(rut, '.', ''), ' ', '')) = ?",
                [$normalized]
            )
            ->first();
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
