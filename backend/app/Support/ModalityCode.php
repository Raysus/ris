<?php

namespace App\Support;

/**
 * Códigos de modalidad: almacenamiento interno y DICOM (MWL).
 * ECO era legado español; DICOM usa US (Ultrasound).
 */
class ModalityCode
{
    /** Normaliza grupo/modalidad guardada en BD (acepta ECO legacy). */
    public static function normalizeGroup(?string $group): string
    {
        $g = strtoupper(trim((string) $group));

        return match ($g) {
            'ECO' => 'US',
            default => $g,
        };
    }

    /** Código Modality (0008,0060) para worklist DICOM — máx. 2 caracteres. */
    public static function forDicomWorklist(?string $group): string
    {
        $g = self::normalizeGroup($group);

        return match ($g) {
            'SCANNER', 'CT' => 'CT',
            'RM', 'MRI', 'MR' => 'MR',
            'RX', 'CR', 'DX' => 'DX',
            'US', 'ULTRASOUND' => 'US',
            'MAMO', 'MG' => 'MG',
            default => strlen($g) <= 2 ? $g : 'US',
        };
    }
}
