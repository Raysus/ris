<?php

namespace App\Services;

use App\Models\Laboratory;
use App\Models\LaboratoryType;

class LaboratoryProfileService
{
    public static function resolve(?Laboratory $laboratory = null): array
    {
        $laboratory ??= self::currentLaboratory();
        $code = self::codeFromLaboratory($laboratory);

        return self::profileForCode(
            $code,
            $laboratory?->type?->name,
            is_array($laboratory?->settings) ? $laboratory->settings : null
        );
    }

    public static function codeFromLaboratory(?Laboratory $laboratory): string
    {
        if (!$laboratory) {
            return 'clinical';
        }

        $laboratory->loadMissing('type');
        $typeCode = $laboratory->type?->code;

        if ($typeCode) {
            return strtolower($typeCode);
        }

        return self::codeFromTypeName($laboratory->type?->name);
    }

    public static function codeFromTypeName(?string $name): string
    {
        $n = strtolower((string) $name);

        if (str_contains($n, 'veterin')) {
            return 'veterinary';
        }
        if (str_contains($n, 'dental')) {
            return 'dental';
        }

        return 'clinical';
    }

    public static function profileForCode(string $code, ?string $typeName = null, ?array $labSettings = null): array
    {
        $code = strtolower($code);

        $isClinical = $code === 'clinical';
        $isDental = $code === 'dental';
        $isVeterinary = $code === 'veterinary';

        // Clínico: MWL por defecto. Dental/veterinario: subida manual (CBCT, intraoral, etc.).
        $usesDicomWorklist = $isClinical;
        if (is_array($labSettings) && array_key_exists('uses_dicom_worklist', $labSettings)) {
            $usesDicomWorklist = (bool) $labSettings['uses_dicom_worklist'];
        }

        return [
            'code' => $code,
            'uses_dicom_worklist' => $usesDicomWorklist,
            'dicom_integration_mode' => $usesDicomWorklist ? 'worklist' : 'manual_upload',
            'type_name' => $typeName ?? match ($code) {
                'dental' => 'Centro Dental',
                'veterinary' => 'Veterinario',
                default => 'Clínico Humano',
            },
            'uses_fonasa' => $isClinical,
            'uses_bono' => $isClinical,
            'uses_clinical_insurance' => $isClinical,
            'show_insurance_fields' => $isClinical || $isVeterinary,
            'show_fonasa_panel' => $isClinical,
            'patient_label' => $isVeterinary ? 'Mascota' : 'Paciente',
            'patient_id_label' => $isVeterinary ? 'ID mascota / microchip' : 'RUT / Documento',
            'service_code_label' => $isClinical ? 'Cód. FONASA' : 'Cód. prestación',
            'referring_label' => $isVeterinary ? 'Médico veterinario derivante' : 'Médico derivante',
            'module_label' => match ($code) {
                'dental' => 'Centro dental',
                'veterinary' => 'Centro veterinario',
                default => 'Diagnóstico por imágenes',
            },
            'technician_module' => $usesDicomWorklist ? 'worklist' : 'atencion',
            'technician_module_label' => $usesDicomWorklist ? 'Worklist' : 'Atención en salas',
        ];
    }

    public static function currentLaboratory(): ?Laboratory
    {
        $labId = config('app.current_lab_id');

        if (!$labId) {
            return null;
        }

        return Laboratory::with('type')->find($labId);
    }

    public static function assertUsesFonasa(): void
    {
        $profile = self::resolve();

        if (!($profile['uses_fonasa'] ?? false)) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(
                403,
                'Bonos FONASA no aplican a centros dentales o veterinarios.'
            );
        }
    }

    /**
     * Excluye previsiones FONASA/ISAPRE chilenas en laboratorios no clínicos.
     */
    public static function filterInsurancesForProfile($query, ?array $profile = null)
    {
        $profile ??= self::resolve();

        if ($profile['uses_clinical_insurance'] ?? true) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->where(function ($inner) {
                $inner->whereNull('code')
                    ->orWhereIn('code', ['0', '10']);
            })
                ->whereRaw('UPPER(name) NOT LIKE ?', ['%FONASA%'])
                ->whereRaw('UPPER(name) NOT LIKE ?', ['%ISAPRE%']);
        });
    }
}
