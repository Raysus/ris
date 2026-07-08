<?php

namespace App\Services;

use RuntimeException;

/**
 * Impresión ESC/POS a Epson térmica (TCP :9100, dispositivo USB o RAW).
 * No requiere driver Windows/CUPS: envía bytes crudos al puerto de impresión.
 */
class ThermalEscPosPrinter
{
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
        $width = max(24, (int) ($cfg['width_chars'] ?? 48));
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
        $out = "\x1b@"; // init

        foreach ($ticket['sections'] ?? [] as $section) {
            $align = strtolower((string) ($section['align'] ?? 'left'));
            $out .= match ($align) {
                'center' => "\x1ba\x01",
                'right' => "\x1ba\x02",
                default => "\x1ba\x00",
            };
            $bold = !empty($section['bold']);
            foreach ($section['lines'] ?? [] as $line) {
                if ($bold) {
                    $out .= "\x1bE\x01";
                }
                $out .= $this->latin1(mb_substr((string) $line, 0, $width)) . "\n";
                if ($bold) {
                    $out .= "\x1bE\x00";
                }
            }
            $out .= "\n";
        }

        $out .= "\x1ba\x00";
        if (!empty($ticket['separator'])) {
            $sep = substr(str_repeat((string) $ticket['separator'], $width), 0, $width);
            $out .= $this->latin1($sep) . "\n";
        }

        foreach ($ticket['table_header'] ?? [] as $line) {
            $out .= $this->latin1(mb_substr((string) $line, 0, $width)) . "\n";
        }
        foreach ($ticket['table_rows'] ?? [] as $line) {
            $out .= $this->latin1(mb_substr((string) $line, 0, $width)) . "\n";
        }

        if (!empty($ticket['separator_after_table'])) {
            $sep = substr(str_repeat((string) $ticket['separator_after_table'], $width), 0, $width);
            $out .= $this->latin1($sep) . "\n";
        }

        if (!empty($ticket['total_line'])) {
            $out .= "\x1ba\x02\x1bE\x01";
            $out .= $this->latin1(mb_substr((string) $ticket['total_line'], 0, $width)) . "\n";
            $out .= "\x1bE\x00\x1ba\x00";
        }

        $out .= $this->latin1((string) ($ticket['obs_label'] ?? 'OBS:')) . "\n";
        if (!empty($ticket['obs_text'])) {
            $out .= $this->latin1(mb_substr((string) $ticket['obs_text'], 0, $width * 3)) . "\n";
        }
        $out .= "\n";

        $footer = $ticket['footer'] ?? null;
        $footerLines = is_array($footer) ? $footer : [$footer];
        $out .= "\x1ba\x01";
        foreach ($footerLines as $line) {
            if ($line === null || $line === '') {
                continue;
            }
            $out .= $this->latin1(mb_substr((string) $line, 0, $width)) . "\n";
        }

        $out .= "\n\n\n\x1dV\x00"; // cut

        return $out;
    }

    private function latin1(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);

        return $converted === false ? $text : $converted;
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

        stream_set_timeout($socket, 10);
        $written = fwrite($socket, $buffer);
        fclose($socket);

        if ($written === false || $written < strlen($buffer)) {
            throw new RuntimeException("Escritura incompleta a {$host}:{$port}");
        }
    }
}
