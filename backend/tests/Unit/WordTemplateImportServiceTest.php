<?php

namespace Tests\Unit;

use App\Services\WordTemplateImportService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class WordTemplateImportServiceTest extends TestCase
{
    private function makeDocxFixture(string $paragraphText): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl_docx_') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p><w:r><w:t>' . htmlspecialchars($paragraphText, ENT_XML1) . '</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Segunda línea</w:t></w:r></w:p></w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return new UploadedFile($path, 'plantilla_test.docx', null, null, true);
    }

    public function test_extracts_text_from_docx(): void
    {
        $service = new WordTemplateImportService();
        $file = $this->makeDocxFixture('Informe de prueba');

        $text = $service->extractPlainText($file);

        $this->assertStringContainsString('Informe de prueba', $text);
        $this->assertStringContainsString('Segunda línea', $text);
    }

    public function test_suggest_metadata_from_filename_with_group(): void
    {
        $service = new WordTemplateImportService();
        $meta = $service->suggestMetadataFromFilename('[CT] Tórax normal.docx');

        $this->assertSame('CT', $meta['group_code']);
        $this->assertSame('Tórax normal', $meta['title']);
    }

    public function test_rejects_legacy_doc_extension(): void
    {
        $service = new WordTemplateImportService();
        $path = tempnam(sys_get_temp_dir(), 'tpl_doc_') . '.doc';
        file_put_contents($path, 'not a real doc');
        $file = new UploadedFile($path, 'legacy.doc', null, null, true);

        $this->expectException(\InvalidArgumentException::class);
        $service->extractPlainText($file);
    }
}
