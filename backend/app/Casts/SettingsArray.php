<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * settings JSONB: tolera valores guardados como string (regresión FormData).
 */
class SettingsArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return self::normalize($value ?? ($attributes[$key] ?? null));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => json_encode(self::normalize($value))];
    }

    public static function normalize(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (is_string($decoded) && $decoded !== '') {
            $decodedAgain = json_decode($decoded, true);
            if (is_array($decodedAgain)) {
                return $decodedAgain;
            }
        }

        return [];
    }
}
