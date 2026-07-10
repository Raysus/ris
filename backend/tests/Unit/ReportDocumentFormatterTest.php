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
        $this->assertStringContainsString('Campos pulmonares libres.', $text);
        $this->assertStringNotContainsString('Atentamente,', $text);
        $this->assertStringNotContainsString('DR. HELMUTH RIEDEL ST.', $text);
        $this->assertStringNotContainsString('jrr/HRST', $text);
        $this->assertStringNotContainsString('127959', $text);
    }

    public function test_build_html_omits_signature_when_url_missing(): void
    {
        $document = [
            'headerLines' => ['Centro Demo'],
            'dateLine' => 'Temuco, 19 de junio de 2026.',
            'patientName' => 'Paciente Demo',
            'examTitle' => 'RX. TORAX:',
            'reportBody' => "Hallazgos.\n\nAtentamente,\nDR. DEMO",
            'doctor' => [
                'displayName' => 'DR. HELMUTH RIEDEL ST.',
                'signatureUrl' => null,
            ],
        ];

        $html = ReportDocumentFormatter::buildHtml($document);

        $this->assertStringContainsString('Hallazgos.', $html);
        $this->assertStringContainsString('Atentamente,', $html); // solo si viene en el cuerpo
        $this->assertStringNotContainsString('alt="Firma"', $html);
        $this->assertStringNotContainsString('DR. HELMUTH RIEDEL ST.', $html);
        $this->assertStringNotContainsString('MEDICO RADIÓLOGO', $html);
    }

    public function test_build_html_includes_signature_image_when_url_present(): void
    {
        $document = [
            'headerLines' => ['Centro Demo'],
            'dateLine' => 'Temuco, 19 de junio de 2026.',
            'patientName' => 'Paciente Demo',
            'examTitle' => 'RX. TORAX:',
            'reportBody' => 'Hallazgos.',
            'doctor' => [
                'displayName' => 'DR. HELMUTH RIEDEL ST.',
                'signatureUrl' => 'https://example.test/firma.png',
            ],
        ];

        $html = ReportDocumentFormatter::buildHtml($document);

        $this->assertStringContainsString('firma.png', $html);
        $this->assertStringContainsString('alt="Firma"', $html);
    }

    public function test_lab_uses_report_signature_defaults_false(): void
    {
        $this->assertFalse(ReportDocumentFormatter::labUsesReportSignature(null));
        $this->assertFalse(ReportDocumentFormatter::labUsesReportSignature((object) ['settings' => []]));
        $this->assertTrue(ReportDocumentFormatter::labUsesReportSignature((object) [
            'settings' => ['use_report_signature' => true],
        ]));
    }
}
