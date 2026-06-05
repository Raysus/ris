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
}
