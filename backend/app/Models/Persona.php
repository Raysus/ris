<?php

namespace App\Models;

use App\Casts\LegacyEncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Persona extends Model
{
    use HasUuids;

    protected $fillable = [
        'rut',
        'rut_hash',
        'names',
        'last_name_1',
        'last_name_2',
        'gender',
        'birth_date',
        'email',
        'email_hash',
        'phone',
        'address',
        'has_sso_account',
    ];

    protected $casts = [
        'has_sso_account' => 'boolean',
        'birth_date' => 'date',
        'rut' => LegacyEncryptedString::class,
        'email' => LegacyEncryptedString::class,
        'phone' => LegacyEncryptedString::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (Persona $persona) {
            if ($persona->isDirty('rut') && filled($persona->rut)) {
                $persona->rut = self::normalizeRut($persona->rut);
                $persona->rut_hash = self::hashRut($persona->rut);
            }

            if ($persona->isDirty('email')) {
                $persona->email_hash = filled($persona->email)
                    ? self::hashEmail($persona->email)
                    : null;
            }
        });
    }

    public static function normalizeRut(string $rut): string
    {
        return strtoupper(str_replace(['.', ' '], '', $rut));
    }

    public static function hashRut(string $rut): string
    {
        return hash('sha256', self::normalizeRut($rut));
    }

    public static function hashEmail(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    public static function findByRut(?string $rut): ?self
    {
        if (!filled($rut)) {
            return null;
        }

        return static::where('rut_hash', self::hashRut($rut))->first();
    }

    public static function upsertByRut(string $rut, array $attributes = []): self
    {
        $persona = self::findByRut($rut) ?? new self(['rut' => self::normalizeRut($rut)]);
        $persona->fill($attributes);

        if (! $persona->rut) {
            $persona->rut = self::normalizeRut($rut);
        }

        $persona->save();

        return $persona;
    }

    public function patients()
    {
        return $this->hasMany(Paciente::class);
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }
}
