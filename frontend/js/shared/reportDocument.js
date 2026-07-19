/* Formato carta CDT para informes (validación, entrega, impresión). */
(function (global) {
    'use strict';

    function escapeHtml(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /** Dirección de la sede actual (sin mezclar otras sucursales). */
    function formatLabAddressLine(labInfo) {
        const settings = labInfo?.settings || {};
        const address = String(labInfo?.address || '').trim();
        const city = String(labInfo?.city || '').trim();
        const parts = [];
        if (address) parts.push(address);
        if (city && (!address || !address.toLowerCase().includes(city.toLowerCase()))) {
            parts.push(city);
        }
        let line = parts.join(', ');
        const phone = String(
            labInfo?.phone || settings.phone || settings.fono || settings.telefono || ''
        ).trim();
        if (phone) {
            line = line ? `${line}. Fono: ${phone}` : `Fono: ${phone}`;
        }
        return line.replace(/[.\s]+$/g, '').trim();
    }

    function letterCopy(labInfo) {
        const reportHeader = labInfo?.settings?.reportHeader || {};
        const greeting = String(reportHeader.greeting || '').trim() || 'Estimado Doctor:';
        const patientIntro = String(reportHeader.patientIntro || '').trim()
            || 'El examen realizado a su paciente Sr(a) {patient}, ha dado el siguiente resultado:';
        return { greeting, patientIntro };
    }

    function resolvePatientIntro(template, patientName) {
        return String(template || '')
            .replace(/\{patient\}/gi, String(patientName || 'Paciente'));
    }

    function headerLines(labInfo) {
        const settings = labInfo?.settings || {};
        const reportHeader = settings.reportHeader || {};
        const legalName = (reportHeader.legalName || labInfo?.name || 'Centro de Diagnóstico y Tratamiento Ltda.').trim();

        // Solo usar listado multi-sede si el laboratorio lo configuró explícitamente.
        let branches = Array.isArray(reportHeader.branches) && reportHeader.branches.length
            ? reportHeader.branches.slice()
            : [];

        if (!branches.length) {
            const ownLine = formatLabAddressLine(labInfo);
            if (ownLine) branches = [ownLine];
        }

        const lines = [];
        if (legalName) {
            lines.push(legalName, legalName);
        }
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

    function doctorPayload(chain, labInfo) {
        const name = String(chain?.destinationDoctorName || 'Médico Radiólogo').trim();
        const useSignature = !!(labInfo?.settings?.use_report_signature);
        return {
            displayName: /^DR\.?\s/i.test(name) ? name.toUpperCase() : `DR. ${name.toUpperCase()}`,
            initials: String(chain?.destinationDoctorInitials || '').trim(),
            registration: String(chain?.destinationDoctorRegistration || '').trim(),
            signatureUrl: useSignature
                ? (chain?.firmaUrl || chain?.signatureUrl || null)
                : null,
        };
    }

    function buildStudyDocument(chain, study, labInfo) {
        const city = (labInfo?.city || 'Temuco').trim() || 'Temuco';
        const patientName = formatPatientName(chain?.patient);
        const copy = letterCopy(labInfo);
        return {
            headerLines: headerLines(labInfo),
            dateLine: formatSpanishDate(chain?.start_time || chain?.signatureDate, city),
            patientName,
            greeting: copy.greeting,
            patientIntro: resolvePatientIntro(copy.patientIntro, patientName),
            examTitle: formatExamTitle(study?.exam, study?.subExam),
            reportBody: String(study?.reportText || '').trim(),
            doctor: doctorPayload(chain, labInfo),
        };
    }

    function buildHtml(document, textColor) {
        const color = textColor || '#111111';
        const header = (document.headerLines || [])
            .map((line) => `<div class="ris-report-header-line">${escapeHtml(line)}</div>`)
            .join('');
        const signatureUrl = String(document?.doctor?.signatureUrl || '').trim();
        const signatureBlock = signatureUrl
            ? `<div class="ris-report-signature"><img src="${escapeHtml(signatureUrl)}" alt="Firma"></div>`
            : '';
        const greeting = String(document.greeting || 'Estimado Doctor:').trim() || 'Estimado Doctor:';
        const patientIntro = String(document.patientIntro || '').trim()
            || `El examen realizado a su paciente Sr(a) ${document.patientName || 'Paciente'}, ha dado el siguiente resultado:`;

        return `
<div class="ris-report-page" style="--ris-report-color:${color}">
    <div class="ris-report-header">${header}</div>
    <div class="ris-report-block">${escapeHtml(document.dateLine)}</div>
    <div class="ris-report-block--sm">${escapeHtml(greeting)}</div>
    <div class="ris-report-block--justify">${escapeHtml(patientIntro)}</div>
    <div class="ris-report-exam-title">${escapeHtml(document.examTitle)}</div>
    <div class="ris-report-body">${escapeHtml(document.reportBody)}</div>
    ${signatureBlock}
</div>`;
    }

    function buildMultiStudyHtml(chain, studies, labInfo, textColor) {
        return (studies || []).map((study, index) => {
            const doc = buildStudyDocument(chain, study, labInfo);
            const html = buildHtml(doc, textColor);
            return index > 0 ? `<div class="ris-page-break"></div>${html}` : html;
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
