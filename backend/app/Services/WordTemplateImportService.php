<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use ZipArchive;

class WordTemplateImportService
{
    private const DOCX_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Extrae texto plano desde .docx (Open XML) o .doc (solo si el servidor puede leerlo como ZIP/XML).
     */
    public function extractPlainText(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        $path = $file->getRealPath();

        if ($extension === 'docx' || $this->isDocxZip($path)) {
            return $this->extractFromDocx($path);
        }

        if ($extension === 'doc') {
            throw new \InvalidArgumentException(
                'El formato .doc antiguo no está soportado. Abra el archivo en Word y guárdelo como .docx.'
            );
        }

        throw new \InvalidArgumentException('Formato no válido. Use un archivo Word (.docx).');
    }

    private function isDocxZip(string $path): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $hasDocument = $zip->locateName('word/document.xml') !== false;
        $zip->close();

        return $hasDocument;
    }

    private function extractFromDocx(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('No se pudo abrir el archivo Word.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false || trim($xml) === '') {
            throw new \InvalidArgumentException('El documento no contiene texto legible.');
        }

        $text = $this->parseDocumentXml($xml);
        $text = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text)) ?? $text;

        if ($text === '') {
            throw new \InvalidArgumentException('El documento Word está vacío.');
        }

        return $text;
    }

    private function parseDocumentXml(string $xml): string
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            throw new \InvalidArgumentException('No se pudo leer el contenido del documento Word.');
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::DOCX_NAMESPACE);

        $paragraphs = $xpath->query('//w:p');
        if ($paragraphs === false || $paragraphs->length === 0) {
            return $this->collectTextNodes($xpath);
        }

        $lines = [];
        foreach ($paragraphs as $paragraph) {
            $parts = $xpath->query('.//w:t', $paragraph);
            if ($parts === false || $parts->length === 0) {
                continue;
            }
            $line = '';
            foreach ($parts as $node) {
                $line .= $node->textContent;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function collectTextNodes(\DOMXPath $xpath): string
    {
        $nodes = $xpath->query('//w:t');
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $chunks = [];
        foreach ($nodes as $node) {
            $chunks[] = $node->textContent;
        }

        return implode(' ', $chunks);
    }

    /**
     * Título y modalidad opcionales desde nombre de archivo (ej. "[CT] Tórax normal.docx").
     *
     * @return array{title: string, group_code: string}
     */
    public function suggestMetadataFromFilename(string $filename): array
    {
        $title = trim(pathinfo($filename, PATHINFO_FILENAME));
        $groupCode = 'RX';

        if (preg_match('/^\[(RX|CT|MRI|ECO|MAMO)\]\s*/i', $title, $match)) {
            $groupCode = strtoupper($match[1]);
            $title = trim((string) preg_replace('/^\[[^\]]+\]\s*/i', '', $title));
        }

        if ($title === '') {
            $title = 'Plantilla importada';
        }

        return ['title' => $title, 'group_code' => $groupCode];
    }
}
