/* Formato carta CDT para informes (validación, entrega, impresión). */
(function (global) {
    'use strict';

    const DEFAULT_BRANCHES = [
        'Manuel Montt Nº942, Temuco. Fono: central 452 690000. WhatsApp +569 63128654.',
        'Centro médico 452 690037/39',
        'Dinamarca Nº661, Temuco. Fono 452 888724 Cel. +5696312109',
        'Av. Arturo Prat Nº1130, Victoria. Fono 452 846 238',
        'Av. O´Higgins Nº915, Lautaro. Fono. 452 738218',
    ];

    function escapeHtml(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function headerLines(labInfo) {
        const settings = labInfo?.settings || {};
        const reportHeader = settings.reportHeader || {};
        const legalName = (reportHeader.legalName || labInfo?.name || 'Centro de Diagnóstico y Tratamiento Ltda.').trim();
        let branches = Array.isArray(reportHeader.branches) && reportHeader.branches.length
            ? reportHeader.branches
            : DEFAULT_BRANCHES.slice();

        if (!Array.isArray(reportHeader.branches) || !reportHeader.branches.length) {
            if (labInfo?.address) {
                branches = [labInfo.address, ...branches];
            }
        }

        const lines = [legalName, legalName];
        branches.forEach((line) => {
            const trimmed = String(line || '').trim();
            if (trimmed) lines.push(trimmed);
        });
        return lines;
    }

    function formatSpanishDate(dateInput, city) {
        const cityLabel = (city || 'Temuco').trim() || 'Temuco';
        const date = dateInput ? new Date(dateInput) : new Date();
        if (Number.isNaN(date.getTime())) {
            return `${cityLabel}, ${new Date().toLocaleDateString('es-CL')}.`;
        }
        const formatted = date.toLocaleDateString('es-CL', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
        return `${cityLabel}, ${formatted}.`;
    }

    function formatPatientName(patient) {
        if (!patient) return 'Paciente';
        const parts = [
            patient.name || patient.names || '',
            patient.lastName || patient.last_name_1 || '',
            patient.secondLastName || patient.last_name_2 || '',
        ].map((p) => String(p || '').trim()).filter(Boolean);
        return parts.join(' ') || 'Paciente';
    }

    function formatExamTitle(exam, subExam) {
        let label = String(exam || '').trim();
        const sub = String(subExam || '').trim();
        if (sub && !label.toLowerCase().includes(sub.toLowerCase())) {
            label = label ? `${label} - ${sub}` : sub;
        }
        label = label.toUpperCase();
        if (!label) return 'EXAMEN:';
        if (!/^(RX|TC|RM|US|MG|DX|CR|MR|CT|ECO)\./.test(label)) {
            if (/^(RX|TC|RM|US|MG|DX|CR|MR|CT|ECO)\s+/.test(label)) {
                label = label.replace(/^(RX|TC|RM|US|MG|DX|CR|MR|CT|ECO)\s+/, '$1. ');
            } else if (/\b(RX|TORAX|TÓRAX|COLUMNA|ABDOMEN|CRÁNEO|CRANEO)\b/.test(label)) {
                label = 'RX. ' + label.replace(/^RX\.?\s*/, '');
            }
        }
        return label.replace(/:$/, '') + ':';
    }

    function doctorPayload(chain) {
        const name = String(chain?.destinationDoctorName || 'Médico Radiólogo').trim();
        return {
            displayName: /^DR\.?\s/i.test(name) ? name.toUpperCase() : `DR. ${name.toUpperCase()}`,
            initials: String(chain?.destinationDoctorInitials || '').trim(),
            registration: String(chain?.destinationDoctorRegistration || '').trim(),
            // Firma visual: se incluye en el texto de transcripción (sin imagen automática).
            signatureUrl: null,
        };
    }

    function buildStudyDocument(chain, study, labInfo) {
        const city = (labInfo?.city || 'Temuco').trim() || 'Temuco';
        return {
            headerLines: headerLines(labInfo),
            dateLine: formatSpanishDate(chain?.start_time || chain?.signatureDate, city),
            patientName: formatPatientName(chain?.patient),
            examTitle: formatExamTitle(study?.exam, study?.subExam),
            reportBody: String(study?.reportText || '').trim(),
            doctor: doctorPayload(chain),
        };
    }

    function buildHtml(document, textColor) {
        const color = textColor || '#111111';
        const header = (document.headerLines || [])
            .map((line) => `<div class="ris-report-header-line">${escapeHtml(line)}</div>`)
            .join('');

        return `
<div class="ris-report-page" style="color:${color};font-family:'Times New Roman',Times,serif;font-size:12pt;line-height:1.45;">
    <div class="ris-report-header" style="text-align:center;margin-bottom:18px;">${header}</div>
    <div style="margin-bottom:14px;">${escapeHtml(document.dateLine)}</div>
    <div style="margin-bottom:10px;">Estimado Doctor:</div>
    <div style="margin-bottom:14px;text-align:justify;">
        El examen realizado a su paciente Sr(a) ${escapeHtml(document.patientName)}, ha dado el siguiente resultado:
    </div>
    <div style="font-weight:bold;margin-bottom:10px;">${escapeHtml(document.examTitle)}</div>
    <div class="ris-report-body" style="white-space:pre-wrap;text-align:justify;margin-bottom:24px;">${escapeHtml(document.reportBody)}</div>
</div>`;
    }

    function buildMultiStudyHtml(chain, studies, labInfo, textColor) {
        return (studies || []).map((study, index) => {
            const doc = buildStudyDocument(chain, study, labInfo);
            const html = buildHtml(doc, textColor);
            return index > 0 ? `<div style="page-break-before:always;"></div>${html}` : html;
        }).join('\n');
    }

    function buildPrintDocumentHtml(chain, studies, labInfo, textColor) {
        const body = buildMultiStudyHtml(chain, studies, labInfo, textColor);
        return `<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Informe</title>
<style>
@page { margin: 18mm 16mm; }
body { margin: 0; padding: 0; }
.ris-report-page { padding: 0; }
</style></head><body>${body}</body></html>`;
    }

    function renderPreview(rootEl, chain, study, labInfo, textColor) {
        if (!rootEl) return;
        const doc = buildStudyDocument(chain, study, labInfo);
        rootEl.innerHTML = buildHtml(doc, textColor);
    }

    global.risReportDocument = {
        headerLines,
        formatSpanishDate,
        formatPatientName,
        formatExamTitle,
        buildStudyDocument,
        buildHtml,
        buildMultiStudyHtml,
        buildPrintDocumentHtml,
        renderPreview,
    };
})(window);
