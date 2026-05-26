/**
 * RIS Local Bridge — corre en cada PC de recepción / radiología (no en el servidor).
 * Puerto: 127.0.0.1:8181
 *
 * - GET  /escanear        → NAPS2 → PDF base64 (Agenda)
 * - POST /open-dicom      → RadiAnt, Horos, OsiriX, Weasis (Radiólogo / Validación)
 * - GET  /health          → estado del servicio
 */
const express = require('express');
const cors = require('cors');
const { exec } = require('child_process');
const fs = require('fs');
const path = require('path');

const app = express();
app.use(cors());
app.use(express.json());

const getLocalConfig = () => {
    try {
        const configPath = path.join(process.cwd(), 'config.json');
        if (!fs.existsSync(configPath)) {
            return {
                viewer: 'radiant',
                paths: {
                    radiant: 'C:\\Program Files\\RadiAntViewer64bit\\RadiAntViewer.exe',
                    horos: 'C:\\Program Files\\Horos\\Horos.exe',
                },
                scanner: {
                    naps2_path: 'C:\\Program Files\\NAPS2\\NAPS2.Console.exe',
                    profile: 'Brother',
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

    if (!viewerPath) {
        return res.status(400).json({
            success: false,
            message: `Visor "${type}" sin ruta en config.json → paths.${type}`,
        });
    }

    if (!fs.existsSync(viewerPath)) {
        return res.status(404).json({
            success: false,
            message: `Ejecutable no encontrado: ${viewerPath}`,
            fallback: 'ohif',
        });
    }

    const ip = pacs_ip || '127.0.0.1';
    const port = pacs_port || 4242;
    const aet = pacs_aet || 'ORTHANC';

    let cmd = '';

    switch (type) {
        case 'radiant':
            cmd = `"${viewerPath}" -pae ${aet} -pait ${ip} -papt ${port} -v ${accession_number}`;
            break;
        case 'horos':
        case 'osirix':
            cmd = `"${viewerPath}" "${accession_number}"`;
            break;
        case 'weasis':
            cmd = `"${viewerPath}" "$dicom:rs --url http://${ip}:${port}/dicom-web -r AccessionNumber=${accession_number}"`;
            break;
        default:
            return res.status(400).json({ success: false, message: `Visor no soportado: ${type}` });
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

const PORT = 8181;
app.listen(PORT, '127.0.0.1', () => {
    console.log(`RIS Local Bridge → http://127.0.0.1:${PORT}`);
    console.log(`Config: ${path.join(process.cwd(), 'config.json')}`);
});
