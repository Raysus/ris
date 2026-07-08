<?php

namespace App\Services;

use RuntimeException;

/**
 * Impresión ESC/POS a Epson térmica (TCP :9100, dispositivo USB o RAW).
 * Formato alineado al comprobante SIRESA legacy (Font A, CP850).
 */
class ThermalEscPosPrinter
{
    private const ESC = "\x1b";
    private const GS = "\x1d";

    public function isConfigured(): bool
    {
        $cfg = config('services.thermal_printer', []);

        return !empty($cfg['enabled']) && filled($cfg['interface'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    public function printTicket(array $ticket, ?int $copies = null): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Impresora térmica no configurada (THERMAL_PRINTER_ENABLED / THERMAL_PRINTER_INTERFACE).');
        }

        $cfg = config('services.thermal_printer', []);
        $width = max(24, (int) ($cfg['width_chars'] ?? 42));
        $copies = max(1, $copies ?? (int) ($cfg['copies'] ?? 1));
        $buffer = $this->buildBuffer($ticket, $width);

        for ($i = 0; $i < $copies; $i++) {
            $this->send((string) $cfg['interface'], $buffer);
        }
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function buildBuffer(array $ticket, int $width): string
    {
        $out = self::ESC . '@';
        $out .= self::ESC . 'M' . "\x00"; // Font A (12x24, como comprobante original)
        $out .= self::ESC . '2'; // Interlineado por defecto

        foreach ($ticket['sections'] ?? [] as $section) {
            $out .= $this->alignCommand((string) ($section['align'] ?? 'left'));

            foreach ($section['lines'] ?? [] as $line) {
                $out .= $this->renderLine($line, $width, !empty($section['bold']));
            }

            if (!empty($section['blank_after'])) {
                $out .= "\n";
            }
        }

        $out .= $this->alignCommand('left');
        if (!empty($ticket['separator'])) {
            $out .= $this->textLine($this->repeatChar((string) $ticket['separator'], $width));
        }

        foreach ($ticket['table_header'] ?? [] as $line) {
            $out .= $this->textLine(mb_substr((string) $line, 0, $width));
        }
        foreach ($ticket['table_rows'] ?? [] as $line) {
            $out .= $this->textLine(mb_substr((string) $line, 0, $width));
        }

        if (!empty($ticket['separator_after_table'])) {
            $out .= $this->textLine($this->repeatChar((string) $ticket['separator_after_table'], $width));
        }

        if (!empty($ticket['total_line'])) {
            $out .= $this->alignCommand('right');
            $out .= self::GS . '!' . "\x11"; // doble alto + ancho
            $out .= self::ESC . 'E' . "\x01";
            $out .= $this->encode(mb_substr((string) $ticket['total_line'], 0, (int) ($width / 2))) . "\n";
            $out .= self::ESC . 'E' . "\x00";
            $out .= self::GS . '!' . "\x00";
            $out .= $this->alignCommand('left');
        }

        $out .= $this->textLine((string) ($ticket['obs_label'] ?? 'OBS:'));
        if (!empty($ticket['obs_text'])) {
            $out .= $this->textLine(mb_substr((string) $ticket['obs_text'], 0, $width * 3));
        }
        $out .= "\n";

        $footer = $ticket['footer'] ?? null;
        $footerLines = is_array($footer) ? $footer : [$footer];
        $out .= $this->alignCommand('center');
        foreach ($footerLines as $line) {
            if ($line === null || $line === '') {
                continue;
            }
            $out .= $this->textLine(mb_substr((string) $line, 0, $width));
        }

        $out .= $this->feedAndCut();

        return $out;
    }

    /** Avanza papel y corta (Epson TM: ESC d + GS V 65). */
    private function feedAndCut(): string
    {
        $feed = max(3, min(15, (int) (config('services.thermal_printer.cut_feed_lines') ?? 6)));

        return self::ESC . 'd' . chr($feed)
            . self::GS . 'V' . "\x41" . chr($feed)
            . self::GS . 'V' . "\x00";
    }

    /**
     * @param  string|array{text?: string, style?: string}  $line
     */
    private function renderLine(string|array $line, int $width, bool $sectionBold): string
    {
        $text = is_array($line) ? (string) ($line['text'] ?? '') : (string) $line;
        $style = is_array($line) ? (string) ($line['style'] ?? 'normal') : 'normal';
        if ($sectionBold && $style === 'normal') {
            $style = 'bold';
        }

        $prefix = '';
        $suffix = '';
        $maxWidth = $width;

        if ($style === 'large') {
            $prefix = self::GS . '!' . "\x11";
            $suffix = self::GS . '!' . "\x00";
            $maxWidth = max(1, (int) floor($width / 2));
        } elseif ($style === 'bold') {
            $prefix = self::ESC . 'E' . "\x01";
            $suffix = self::ESC . 'E' . "\x00";
        }

        return $prefix . $this->encode(mb_substr($text, 0, $maxWidth)) . "\n" . $suffix;
    }

    private function textLine(string $text): string
    {
        return $this->encode($text) . "\n";
    }

    private function alignCommand(string $align): string
    {
        return match (strtolower($align)) {
            'center' => self::ESC . 'a' . "\x01",
            'right' => self::ESC . 'a' . "\x02",
            default => self::ESC . 'a' . "\x00",
        };
    }

    private function repeatChar(string $char, int $width): string
    {
        $ch = $char !== '' ? mb_substr($char, 0, 1) : '-';

        return mb_substr(str_repeat($ch, $width), 0, $width);
    }

    private function encode(string $text): string
    {
        $converted = @iconv('UTF-8', 'CP850//IGNORE', $text);

        return $converted !== false ? $converted : $text;
    }

    private function send(string $interface, string $buffer): void
    {
        $iface = trim($interface);
        if (str_starts_with($iface, 'tcp://')) {
            $hostPort = substr($iface, 6);
            [$host, $port] = array_pad(explode(':', $hostPort, 2), 2, '9100');
            $this->sendTcp($host, (int) $port, $buffer);

            return;
        }

        if ($iface === '' || $iface === 'none') {
            throw new RuntimeException('THERMAL_PRINTER_INTERFACE vacío.');
        }

        $written = @file_put_contents($iface, $buffer);
        if ($written === false) {
            throw new RuntimeException("No se pudo escribir en {$iface}");
        }
    }

    private function sendTcp(string $host, int $port, string $buffer): void
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 8);
        if (!$socket) {
            throw new RuntimeException("No se pudo conectar a {$host}:{$port} ({$errno} {$errstr})");
        }

        stream_set_timeout($socket, 15);
        $written = fwrite($socket, $buffer);
        fflush($socket);
        // La cortadora Epson necesita un instante tras recibir el buffer por TCP.
        usleep(800000);
        fclose($socket);

        if ($written === false || $written < strlen($buffer)) {
            throw new RuntimeException("Escritura incompleta a {$host}:{$port}");
        }
    }
}
