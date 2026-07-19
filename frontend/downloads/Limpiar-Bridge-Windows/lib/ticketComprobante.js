const net = require('net');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFile } = require('child_process');

const ESC = '\x1b';
const GS = '\x1d';

/** Quita NBSP, comillas tipográficas y caracteres que salen como basura en Epson. */
function sanitizeEscPosText(text) {
    return String(text ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[\u00a0\u202f\u2007\u2009\u2008\u200a\ufeff]/g, ' ')
        .replace(/[–—―]/g, '-')
        .replace(/[“”«»„]/g, '"')
        .replace(/[‘’‚‛]/g, "'")
        .replace(/[•·]/g, '-')
        .replace(/[^\x09\x0a\x0d\x20-\x7e]/g, '')
        .replace(/ {2,}/g, ' ');
}

class EscPosBuilder {
    constructor(width = 48) {
        this.width = width;
        // Init + Font A + interlineado + impresión oscura (doble golpe + énfasis)
        this.parts = [
            ESC + '@',
            ESC + 'M\x00',
            ESC + '2',
            ESC + 'G\x01', // double-strike
            ESC + 'E\x01', // emphasized / bold
        ];
    }

    /** Densidad Epson TM (0=claro … 8=muy oscuro). GS ( K fn=50. */
    setPrintDensity(level = 6) {
        const m = Math.max(0, Math.min(8, Number(level) || 6));
        this.parts.push(GS + '(K\x02\x002H' + String.fromCharCode(m));
        return this;
    }

    alignLeft() {
        this.parts.push(ESC + 'a\x00');
        return this;
    }

    alignCenter() {
        this.parts.push(ESC + 'a\x01');
        return this;
    }

    alignRight() {
        this.parts.push(ESC + 'a\x02');
        return this;
    }

    bold(on = true) {
        // En modo oscuro el énfasis global ya está ON; no lo apagamos al “normal”.
        if (on) this.parts.push(ESC + 'E\x01');
        return this;
    }

    line(text = '') {
        this.parts.push(sanitizeEscPosText(text).slice(0, this.width) + '\n');
        return this;
    }

    blank() {
        this.parts.push('\n');
        return this;
    }

    separator(char = '-') {
        const ch = sanitizeEscPosText(char).slice(0, 1) || '-';
        this.parts.push(ch.repeat(this.width).slice(0, this.width) + '\n');
        return this;
    }

    /** Alimenta papel y corta (parcial + total) para Epson TM. */
    cut(feedLines = 10) {
        const feed = Math.max(6, Math.min(20, feedLines));
        this.parts.push('\n\n');
        this.parts.push(ESC + 'd' + String.fromCharCode(feed));
        this.parts.push(GS + 'V\x42' + String.fromCharCode(Math.min(15, feed)));
        this.parts.push(GS + 'V\x00');
        return this;
    }

    toBuffer() {
        return Buffer.from(this.parts.join(''), 'latin1');
    }
}

function parseInterface(iface) {
    const raw = String(iface || '').trim();
    if (!raw) return { type: 'none' };

    if (raw.startsWith('tcp://')) {
        const hostPort = raw.slice(6);
        const [host, portStr] = hostPort.split(':');
        return { type: 'tcp', host, port: Number(portStr) || 9100 };
    }

    if (raw.startsWith('printer:') || raw.startsWith('share:')) {
        return { type: 'windows', name: raw.replace(/^(printer|share):/, '') };
    }

    if (raw.startsWith('\\\\')) {
        return { type: 'windows', name: raw };
    }

    if (os.platform() === 'win32') {
        return { type: 'windows', name: raw };
    }

    return { type: 'file', path: raw };
}

function sendTcp(host, port, buffer) {
    return new Promise((resolve, reject) => {
        const socket = net.createConnection({ host, port }, () => {
            socket.write(buffer, (err) => {
                if (err) {
                    socket.destroy();
                    reject(err);
                    return;
                }
                setTimeout(() => {
                    socket.end();
                    resolve();
                }, 1500);
            });
        });
        socket.setTimeout(15000, () => {
            socket.destroy();
            reject(new Error(`Timeout conectando a ${host}:${port}`));
        });
        socket.on('error', reject);
    });
}

function sendWindowsPrinter(printerName, buffer) {
    return new Promise((resolve, reject) => {
        const tmp = path.join(os.tmpdir(), `ris-ticket-${Date.now()}.bin`);
        fs.writeFileSync(tmp, buffer);

        const psScript = `
$path = '${tmp.replace(/'/g, "''")}'
$printer = '${String(printerName).replace(/'/g, "''")}'
$bytes = [System.IO.File]::ReadAllBytes($path)
Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;
public class RawPrinter {
    [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Ansi)]
    public class DOCINFOA { public string pDocName; public string pOutputFile; public string pDataType; }
    [DllImport("winspool.drv", CharSet=CharSet.Ansi, SetLastError=true)]
    public static extern bool OpenPrinter(string szPrinter, out IntPtr hPrinter, IntPtr pd);
    [DllImport("winspool.drv", SetLastError=true)]
    public static extern bool ClosePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", CharSet=CharSet.Ansi, SetLastError=true)]
    public static extern bool StartDocPrinter(IntPtr hPrinter, int level, [In] DOCINFOA di);
    [DllImport("winspool.drv", SetLastError=true)]
    public static extern bool EndDocPrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", SetLastError=true)]
    public static extern bool StartPagePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", SetLastError=true)]
    public static extern bool EndPagePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", SetLastError=true)]
    public static extern bool WritePrinter(IntPtr hPrinter, byte[] pBytes, int dwCount, out int dwWritten);
    public static bool Send(string printer, byte[] bytes) {
        IntPtr h;
        if (!OpenPrinter(printer, out h, IntPtr.Zero)) return false;
        var di = new DOCINFOA { pDocName = "RIS Comprobante", pDataType = "RAW" };
        if (!StartDocPrinter(h, 1, di)) { ClosePrinter(h); return false; }
        if (!StartPagePrinter(h)) { EndDocPrinter(h); ClosePrinter(h); return false; }
        int written;
        bool ok = WritePrinter(h, bytes, bytes.Length, out written);
        EndPagePrinter(h); EndDocPrinter(h); ClosePrinter(h);
        return ok;
    }
}
"@
$ok = [RawPrinter]::Send($printer, $bytes)
Remove-Item -Force $path
if (-not $ok) { throw "No se pudo enviar a la impresora $printer" }
`;

        execFile(
            'powershell.exe',
            ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', psScript],
            (error) => {
                try { fs.unlinkSync(tmp); } catch (_) { /* ignore */ }
                if (error) reject(error);
                else resolve();
            }
        );
    });
}

function sendFile(devicePath, buffer) {
    return new Promise((resolve, reject) => {
        fs.writeFile(devicePath, buffer, (err) => {
            if (err) reject(err);
            else resolve();
        });
    });
}

function buildBufferFromPayload(payload, width, cutFeedLines = 10, density = 6) {
    const b = new EscPosBuilder(width);
    b.setPrintDensity(density);

    (payload?.sections || []).forEach((section) => {
        const align = (section.align || 'left').toLowerCase();
        if (align === 'center') b.alignCenter();
        else if (align === 'right') b.alignRight();
        else b.alignLeft();

        (section.lines || []).forEach((line) => {
            const text = line && typeof line === 'object' ? (line.text ?? '') : String(line ?? '');
            const style = line && typeof line === 'object' ? (line.style || 'normal') : (section.bold ? 'bold' : 'normal');
            if (style === 'large') {
                b.parts.push(GS + '!\x11');
                b.line(text.slice(0, Math.max(1, Math.floor(width / 2))));
                b.parts.push(GS + '!\x00');
                return;
            }
            b.line(text);
        });
        if (section.blank_after) b.blank();
    });

    b.alignLeft();
    if (payload?.separator) b.separator(payload.separator);

    (payload?.table_header || []).forEach((line) => b.line(line));
    (payload?.table_rows || []).forEach((line) => b.line(line));

    if (payload?.separator_after_table) b.separator(payload.separator_after_table);

    if (payload?.total_line) {
        b.alignRight();
        b.parts.push(GS + '!\x11');
        b.line(payload.total_line);
        b.parts.push(GS + '!\x00');
        b.alignLeft();
    }

    if (payload?.obs_label) {
        b.line(payload.obs_label);
        if (payload?.obs_text) b.line(payload.obs_text);
    }

    if (payload?.footer) {
        b.blank().alignCenter();
        const footerLines = Array.isArray(payload.footer) ? payload.footer : [payload.footer];
        footerLines.forEach((line) => b.line(line));
    }

    b.blank().cut(cutFeedLines);
    return b.toBuffer();
}

async function printComprobante(config, payload) {
    const printerCfg = config?.printer;
    if (!printerCfg?.enabled) {
        throw new Error('Impresora deshabilitada en config.json (printer.enabled)');
    }
    if (!printerCfg?.interface) {
        throw new Error('Configure printer.interface (tcp://IP, printer:Nombre en Windows, o /dev/usb/lp0)');
    }

    const width = Number(printerCfg.width_chars) || 42;
    const copiesFromTicket = Number(payload?.copies);
    const copies = Math.max(
        1,
        Number.isFinite(copiesFromTicket) && copiesFromTicket > 0
            ? copiesFromTicket
            : (Number(printerCfg.copies) || 3)
    );
    const cutFeedLines = Math.max(6, Math.min(20, Number(printerCfg.cut_feed_lines) || 10));
    const density = Math.max(0, Math.min(8, Number(printerCfg.print_density ?? printerCfg.darkness) || 6));
    const buffer = buildBufferFromPayload(payload, width, cutFeedLines, density);
    const target = parseInterface(printerCfg.interface);

    for (let i = 0; i < copies; i += 1) {
        switch (target.type) {
            case 'tcp':
                await sendTcp(target.host, target.port, buffer);
                break;
            case 'windows':
                await sendWindowsPrinter(target.name, buffer);
                break;
            case 'file':
                await sendFile(target.path, buffer);
                break;
            default:
                throw new Error('printer.interface no válido');
        }
        if (i < copies - 1) {
            await new Promise((r) => setTimeout(r, 400));
        }
    }

    return { copies, interface: printerCfg.interface };
}

function labelValue(label, value, width = 48) {
    const lab = String(label || '').trim();
    const val = String(value || '').trim();
    const valMax = Math.max(1, width - lab.length - 1);
    if (val.length <= valMax) {
        return (lab + ' '.repeat(width - lab.length - val.length) + val).slice(0, width);
    }
    return (lab + ' ' + val.slice(0, valMax)).slice(0, width);
}

function twoColumns(left, right, width = 48) {
    const r = String(right || '');
    const leftMax = Math.max(1, width - r.length - 1);
    const l = String(left || '').slice(0, leftMax).padEnd(leftMax);
    return (l + ' ' + r).slice(0, width);
}

module.exports = {
    EscPosBuilder,
    sanitizeEscPosText,
    labelValue,
    twoColumns,
    printComprobante,
};
