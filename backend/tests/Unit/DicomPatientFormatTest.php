<?php

namespace Tests\Unit;

use App\Services\DicomImportService;
use PHPUnit\Framework\TestCase;

class DicomPatientFormatTest extends TestCase
{
    private DicomImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DicomImportService;
    }

    public function test_patient_name_family_given_middle(): void
    {
        $pn = $this->service->formatPatientNameDicom('Alan Gerardo', 'Vásquez', 'Hertling');

        $this->assertSame('VÁSQUEZ HERTLING^ALAN GERARDO', $pn);
    }

    public function test_patient_name_without_second_surname(): void
    {
        $pn = $this->service->formatPatientNameDicom('Alan Gerardo', 'Vásquez');

        $this->assertSame('VÁSQUEZ^ALAN GERARDO', $pn);
    }

    public function test_normalize_patient_sex(): void
    {
        $this->assertSame('M', $this->service->normalizePatientSex('M'));
        $this->assertSame('F', $this->service->normalizePatientSex('Femenino'));
        $this->assertSame('', $this->service->normalizePatientSex(''));
    }

    public function test_format_patient_birth_date(): void
    {
        $this->assertSame('19900515', $this->service->formatPatientBirthDate('1990-05-15'));
        $this->assertSame('', $this->service->formatPatientBirthDate(null));
    }

    public function test_worklist_patient_name_ascii_for_fcr(): void
    {
        $pn = $this->service->formatPatientNameDicomWorklist('Alan Gerardo', 'Vásquez', 'Hertling');

        $this->assertSame('VASQUEZ HERTLING^ALAN GERARDO', $pn);
    }

    public function test_resolve_persona_name_fields_with_legacy_columns(): void
    {
        $persona = (object) [
            'name' => 'Maria Fernanda',
            'last_name' => 'Gonzalez',
            'second_last_name' => 'Rodriguez',
        ];

        $fields = $this->service->resolvePersonaNameFields($persona);

        $this->assertSame('Maria Fernanda', $fields['names']);
        $this->assertSame('Gonzalez', $fields['last_name_1']);
        $this->assertSame('Rodriguez', $fields['last_name_2']);
    }

    public function test_format_persona_patient_name_for_worklist_uses_full_names(): void
    {
        $persona = (object) [
            'names' => 'Maria Fernanda',
            'last_name_1' => 'Gonzalez',
            'last_name_2' => 'Rodriguez',
        ];

        $pn = $this->service->formatPersonaPatientNameForWorklist($persona);

        $this->assertSame('GONZALEZ RODRIGUEZ^MARIA FERNANDA', $pn);
    }

    public function test_normalize_patient_id_strips_rut_punctuation(): void
    {
        $this->assertSame('181977876', $this->service->normalizePatientIdDicom('18.197.787-6'));
    }
}
