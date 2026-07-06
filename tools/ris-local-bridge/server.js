/**
 * RIS Local Bridge — corre en cada PC de recepción / radiología (no en el servidor).
 * Puerto: 127.0.0.1:8181
 *
 * - GET  /escanear              → NAPS2 → PDF base64 (Agenda)
 * - POST /open-dicom            → RadiAnt, Horos, OsiriX, Weasis (Radiólogo / Validación)
 * - POST /imprimir-comprobante  → Epson térmica ESC/POS (Agenda)
 * - GET  /health                → estado del servicio
 */
const express = require('express');
const cors = require('cors');
const { exec } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { printComprobante } = require('./lib/ticketComprobante');

const app = express();
app.use(cors());
app.use(express.json());

const getLocalConfig = () => {
    try {
        const configPath = path.join(process.cwd(), 'config.json');
        if (!fs.existsSync(configPath)) {
            const isMac = os.platform() === 'darwin';
            return {
                viewer: isMac ? 'horos' : 'radiant',
                paths: {
                    radiant: 'C:\\Program Files\\RadiAntViewer64bit\\RadiAntViewer.exe',
                    horos: isMac ? '/Applications/Horos.app' : 'C:\\Program Files\\Horos\\Horos.exe',
                    osirix: '/Applications/OsiriX.app',
                },
                scanner: {
                    naps2_path: isMac
                        ? '/Applications/NAPS2.app/Contents/MacOS/NAPS2.Console'
                        : 'C:\\Program Files\\NAPS2\\NAPS2.Console.exe',
                    profile: 'Default',
                },
                printer: {
                    enabled: false,
                    interface: '',
                    width_chars: 48,
                    copies: 1,
                },
            };
        }
        return JSON.parse(fs.readFileSync(configPath, 'utf8'));
    } catch (err) {
        console.error('Error leyendo config.json:', err);
        return null;
    }
};

app.get('/health', (req, res) => {
    const config = getLocalConfig();
    res.json({
        success: true,
        service: 'ris-local-bridge',
        port: 8181,
        viewer: config?.viewer || null,
        printer: config?.printer?.enabled ? config.printer.interface : null,
        config_path: path.join(process.cwd(), 'config.json'),
    });
});

app.get('/escanear', (req, res) => {
    const config = getLocalConfig();
    if (!config?.scanner?.naps2_path) {
        return res.status(500).json({ success: false, message: 'Scanner no configurado en config.json' });
    }

    const outputDir = path.join(__dirname, 'scans');
    if (!fs.existsSync(outputDir)) fs.mkdirSync(outputDir, { recursive: true });

    const outputFile = path.join(outputDir, `temp_scan_${Date.now()}.pdf`);
    const naps2 = config.scanner.naps2_path;
    const profile = config.scanner.profile || 'Default';
    const cmd = `"${naps2}" -o "${outputFile}" --profile "${profile}" --force`;

    exec(cmd, (error) => {
        if (error) {
            return res.status(500).json({ success: false, message: 'Escáner no detectado o NAPS2 falló' });
        }
        try {
            if (fs.existsSync(outputFile)) {
                const base64 = `data:application/pdf;base64,${fs.readFileSync(outputFile).toString('base64')}`;
                fs.unlinkSync(outputFile);
                return res.json({ success: true, file: base64 });
            }
            res.status(500).json({ success: false, message: 'Error al generar archivo' });
        } catch (err) {
            res.status(500).json({ success: false, message: err.message });
        }
    });
});

function resolveMacAppName(viewerPath, fallback) {
    const base = path.basename(String(viewerPath || '').replace(/\/$/, ''));
    if (base.endsWith('.app')) {
        return base.slice(0, -4);
    }
    return fallback;
}

function buildOpenDicomCommand(config, accession_number, pacs_ip, pacs_port, pacs_aet) {
    const type = (config.viewer || 'radiant').toLowerCase();
    const viewerPath = config.paths?.[type];
    const ip = pacs_ip || '127.0.0.1';
    const port = pacs_port || 4242;
    const aet = pacs_aet || 'ORTHANC';
    const isMac = os.platform() === 'darwin';
    const safeAcc = String(accession_number).replace(/"/g, '');

    switch (type) {
        case 'radiant':
            return `"${viewerPath}" -pae ${aet} -pait ${ip} -papt ${port} -v ${safeAcc}`;
        case 'horos':
        case 'osirix':
            if (isMac) {
                const appName = resolveMacAppName(viewerPath, type === 'horos' ? 'Horos' : 'OsiriX');
                if (viewerPath && fs.existsSync(viewerPath)) {
                    return `open -a "${viewerPath}" --args "${safeAcc}"`;
                }
                return `open -a "${appName}" --args "${safeAcc}"`;
            }
            return `"${viewerPath}" "${safeAcc}"`;
        case 'weasis':
            return `"${viewerPath}" "$dicom:rs --url http://${ip}:${port}/dicom-web -r AccessionNumber=${safeAcc}"`;
        default:
            return null;
    }
}

app.post('/open-dicom', (req, res) => {
    const config = getLocalConfig();
    if (!config) {
        return res.status(500).json({ success: false, message: 'config.json inválido' });
    }

    const { accession_number, pacs_ip, pacs_port, pacs_aet } = req.body || {};
    if (!accession_number) {
        return res.status(400).json({ success: false, message: 'Falta accession_number' });
    }

    const type = (config.viewer || 'radiant').toLowerCase();
    const viewerPath = config.paths?.[type];

    if (!viewerPath && !(os.platform() === 'darwin' && (type === 'horos' || type === 'osirix'))) {
        return res.status(400).json({
            success: false,
            message: `Visor "${type}" sin ruta en config.json → paths.${type}`,
        });
    }

    const ip = pacs_ip || '127.0.0.1';
    const port = pacs_port || 4242;
    const aet = pacs_aet || 'ORTHANC';

    const cmd = buildOpenDicomCommand(config, accession_number, ip, port, aet);
    if (!cmd) {
        return res.status(400).json({ success: false, message: `Visor no soportado: ${type}` });
    }

    const existsCheckPath = viewerPath || '';
    const macHorosFallback = os.platform() === 'darwin' && (type === 'horos' || type === 'osirix');
    if (existsCheckPath && !fs.existsSync(existsCheckPath) && !macHorosFallback) {
        return res.status(404).json({
            success: false,
            message: `Ejecutable no encontrado: ${viewerPath}`,
            fallback: 'ohif',
        });
    }

    exec(cmd, (error) => {
        if (error) {
            console.error(error);
            return res.status(500).json({
                success: false,
                message: 'Error al abrir visor local',
                fallback: 'ohif',
            });
        }
        res.json({ success: true, viewer: type });
    });
});

app.post('/imprimir-comprobante', async (req, res) => {
    const config = getLocalConfig();
    if (!config) {
        return res.status(500).json({ success: false, message: 'config.json inválido' });
    }

    const ticket = req.body?.ticket || req.body;
    if (!ticket || !Array.isArray(ticket.sections)) {
        return res.status(400).json({ success: false, message: 'Falta ticket.sections en el cuerpo JSON' });
    }

    try {
        const result = await printComprobante(config, ticket);
        res.json({ success: true, ...result });
    } catch (err) {
        console.error('Error imprimiendo comprobante:', err);
        res.status(500).json({
            success: false,
            message: err.message || 'Error al imprimir',
        });
    }
});

const PORT = 8181;
app.listen(PORT, '127.0.0.1', () => {
    console.log(`RIS Local Bridge → http://127.0.0.1:${PORT}`);
    console.log(`Config: ${path.join(process.cwd(), 'config.json')}`);
});
