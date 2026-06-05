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
            'SCANNER', 'TC' => 'CT',
            'RM', 'MR' => 'MRI',
            'MG' => 'MAMO',
            'DENSITO' => 'DEXA',
            // RX genérico legado; salas nuevas usan CR o DX por separado
            default => $g,
        };
    }

    /** Código Modality (0008,0060) para worklist DICOM — máx. 2 caracteres. */
    public static function forDicomWorklist(?string $group): string
    {
        $g = self::normalizeGroup($group);

        return match ($g) {
            'SCANNER', 'CT', 'CBCT' => 'CT',
            'RM', 'MRI', 'MR' => 'MR',
            'CR' => 'CR',
            'DX', 'RX' => 'DX',
            'IO' => 'IO',
            'US', 'ULTRASOUND' => 'US',
            'MAMO', 'MG' => 'MG',
            'DEXA' => 'DX',
            'NM' => 'NM',
            'PT' => 'PT',
            'RF' => 'RF',
            'XA' => 'XA',
            default => strlen($g) <= 2 ? $g : 'OT',
        };
    }
}
