<?php

namespace App\Support;

final class DemoLaboratoryNames
{
    /** Laboratorios de demostración (no SIRESA). */
    public const LABORATORY_NAMES = [
        'Centro de Diagnóstico RIS PRO',
        'Sucursal Sur',
        'Dental Demo — CBCT Temuco',
        'Dental Demo — Sucursal Centro',
        'Veterinaria Demo Sur',
        'Veterinaria Demo — Urgencias 24h',
    ];

    public static function isDemoName(string $name): bool
    {
        return in_array($name, self::LABORATORY_NAMES, true);
    }
}
