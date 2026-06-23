<?php

namespace Tests\Unit;

use App\Services\ReportDocumentFormatter;
use Carbon\Carbon;
use Tests\TestCase;

class ReportDocumentFormatterTest extends TestCase
{
    public function test_format_exam_title_adds_rx_prefix(): void
    {
        $this->assertSame('RX. TORAX PA -LAT:', ReportDocumentFormatter::formatExamTitle('TORAX PA -LAT'));
        $this->assertSame(
            'RX. COLUMNA TOTAL PANORAMICA AP-LAT DE PIE Y DESCALZO:',
            ReportDocumentFormatter::formatExamTitle('COLUMNA TOTAL PANORAMICA AP-LAT DE PIE Y DESCALZO')
        );
    }

    public function test_format_spanish_date(): void
    {
        Carbon::setLocale('es');
        $line = ReportDocumentFormatter::formatSpanishDate(
            Carbon::parse('2026-06-19'),
            'Temuco'
        );
        $this->assertSame('Temuco, 19 de junio de 2026.', $line);
    }

    public function test_build_plain_text_includes_letter_sections(): void
    {
        $document = [
            'headerLines' => ['Centro de Diagnóstico y Tratamiento Ltda.', 'Centro de Diagnóstico y Tratamiento Ltda.'],
            'dateLine' => 'Temuco, 19 de junio de 2026.',
            'patientName' => 'Brayan Sebastián Da Costa Oñate',
            'examTitle' => 'RX. TORAX PA -LAT:',
            'reportBody' => "Hallazgos:\nCampos pulmonares libres.\n\nResumen:\nSin hallazgos.",
            'doctor' => [
                'displayName' => 'DR. HELMUTH RIEDEL ST.',
                'initials' => 'jrr/HRST',
                'registration' => '127959',
            ],
        ];

        $text = ReportDocumentFormatter::buildPlainText($document);

        $this->assertStringContainsString('Estimado Doctor:', $text);
        $this->assertStringContainsString('Sr(a) Brayan Sebastián Da Costa Oñate', $text);
        $this->assertStringContainsString('RX. TORAX PA -LAT:', $text);
        $this->assertStringContainsString('Atentamente,', $text);
        $this->assertStringContainsString('DR. HELMUTH RIEDEL ST.', $text);
        $this->assertStringContainsString('jrr/HRST', $text);
        $this->assertStringContainsString('127959', $text);
    }
}
