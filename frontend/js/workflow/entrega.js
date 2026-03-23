/* =========================================
   MÓDULO DE ENTREGA DE RESULTADOS (entrega.js)
   ========================================= */

function initEntrega() { loadRISState(); renderTablaEntrega(); setupSincronizacionEntrega(); }

function setupSincronizacionEntrega() {
    window.addEventListener('storage', (e) => { if (e.key === 'ris_app_data') { loadRISState(); renderTablaEntrega(); } });
    window.addEventListener('ris_updated', () => { if ($("#tablaEntrega").length) renderTablaEntrega(); });
}

function renderTablaEntrega() {
    const tbody = $("#tablaEntrega tbody");
    const search = $("#searchEntrega").val() ? $("#searchEntrega").val().toLowerCase() : "";
    const filtroEstado = $("#filtroEstadoEntrega").val();

    if (!tbody.length) return;
    tbody.empty();

    const cadenas = {};
    (window.RIS.worklist || []).forEach(item => {
        const acc = item.accessionNumber || item.id;
        item.studies.forEach(study => {
            if (study.reportStatus === 'entregable' || study.reportStatus === 'entregado') {
                if (!cadenas[acc]) cadenas[acc] = { accessionNumber: acc, patient: item.patient, status: 'entregable', items: [], allExams: [] };
                if (study.reportStatus === 'entregado') cadenas[acc].status = 'entregado';
                cadenas[acc].items.push(item);
                cadenas[acc].allExams.push(study.exam);
            }
        });
    });

    let lista = Object.values(cadenas);
    if (filtroEstado !== 'todos') lista = lista.filter(c => c.status === filtroEstado);
    if (search !== "") lista = lista.filter(c => c.patient.rut.includes(search) || c.patient.name.toLowerCase().includes(search));

    if (lista.length === 0) return tbody.append('<tr><td colspan="6" class="text-center p-5">No hay informes.</td></tr>');

    lista.forEach(cadena => {
        const isEntregado = cadena.status === 'entregado';
        const badge = isEntregado ? `<span class="badge bg-success-subtle text-success border border-success">Entregado</span>` : `<span class="badge bg-primary-subtle text-primary border border-primary">Pendiente</span>`;
        const uniqueExams = [...new Set(cadena.allExams)];
        const examsStr = uniqueExams.map(e => `<span class="d-block"><i class="bi bi-check2 text-success me-1"></i>${e}</span>`).join("");

        tbody.append(`
            <tr class="${isEntregado ? 'bg-light opacity-75' : ''}">
                <td class="ps-4">
                    <div class="fw-bold text-dark">${cadena.patient.lastName}, ${cadena.patient.name}</div>
                    <small class="text-muted">A.N: ${cadena.accessionNumber}</small>
                </td>
                <td class="fw-bold text-secondary">${cadena.patient.rut}</td>
                <td class="small fw-bold text-dark">${examsStr}</td>
                <td class="small text-muted">Reciente</td>
                <td>${badge}</td>
                <td class="text-center pe-4">
                    <div class="btn-group shadow-sm">
                        <button class="btn btn-sm btn-outline-danger fw-bold" onclick="imprimirInformeGlobal('${cadena.accessionNumber}')" title="Imprimir PDF"><i class="bi bi-file-pdf"></i></button>
                        ${!isEntregado ? `<button class="btn btn-sm btn-success fw-bold px-3" onclick="entregarCadena('${cadena.accessionNumber}')">Entregar</button>` : `<button class="btn btn-sm btn-secondary" onclick="deshacerEntrega('${cadena.accessionNumber}')"><i class="bi bi-arrow-counterclockwise"></i></button>`}
                    </div>
                </td>
            </tr>
        `);
    });
}

function entregarCadena(acc) {
    if (confirm("¿Confirmas la entrega al paciente?")) {
        window.RIS.worklist.forEach(w => {
            if (w.accessionNumber === acc || w.id === acc) {
                w.studies.forEach(s => { if (s.reportStatus === 'entregable') s.reportStatus = 'entregado'; });
            }
        });
        saveRISState(); showToast("Resultados entregados.", "success");
    }
}

function deshacerEntrega(acc) {
    window.RIS.worklist.forEach(w => {
        if (w.accessionNumber === acc || w.id === acc) {
            w.studies.forEach(s => { if (s.reportStatus === 'entregado') s.reportStatus = 'entregable'; });
        }
    });
    saveRISState(); showToast("Entrega revertida.", "warning");
}

function imprimirInformeGlobal(accessionNumber) {
    const itemsInChain = window.RIS.worklist.filter(w => w.accessionNumber === accessionNumber || w.id === accessionNumber);
    if (itemsInChain.length === 0) return;

    const baseItem = itemsInChain[0];

    if (!baseItem.informeTexto) {
        return showToast("⚠️ Error: La cadena de estudios no contiene texto validado.", "danger");
    }

    const allExams = [];
    itemsInChain.forEach(i => i.studies.forEach(s => allExams.push(s.exam)));

    const clinicName = window.RIS.config.clinicName || "Centro Médico RIS PRO";
    const clinicAddress = window.RIS.config.clinicAddress || "Sin dirección configurada";

    const printWindow = window.open('', '_blank');

    printWindow.document.write(`
        <html>
        <head>
            <title>Informe - ${baseItem.patient.rut}</title>
            <style>
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; padding: 40px; color: #333; line-height: 1.6; }
                .header { text-align: center; border-bottom: 2px solid #2c3e50; padding-bottom: 20px; margin-bottom: 30px; }
                .header h1 { margin: 0; color: #2c3e50; font-size: 24px; text-transform: uppercase; }
                .clinica-name { color: #7f8c8d; font-size: 14px; margin-top: 5px; }
                .patient-data { border: 1px solid #bdc3c7; padding: 15px; border-radius: 5px; margin-bottom: 30px; font-size: 12px; }
                .patient-data table { width: 100%; }
                .patient-data td { padding: 4px; }
                .bold { font-weight: bold; color: #2c3e50; }
                .report-body { font-size: 14px; text-align: justify; white-space: pre-wrap; margin-bottom: 50px; }
                .footer { margin-top: 50px; text-align: right; }
                .firma { border-top: 1px solid #000; display: inline-block; padding-top: 5px; width: 250px; text-align: center; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Informe Imagenológico Integral</h1>
                <div class="clinica-name">
                    <strong>${clinicName.toUpperCase()}</strong><br>
                    ${clinicAddress}
                </div>
            </div>
            <div class="patient-data">
                <table width="100%">
                    <tr><td><b>Paciente:</b> ${baseItem.patient.name} ${baseItem.patient.lastName}</td><td><b>RUT:</b> ${baseItem.patient.rut}</td></tr>
                    <tr><td><b>Accession:</b> ${accessionNumber}</td><td><b>Fecha:</b> ${new Date().toLocaleDateString('es-CL')}</td></tr>
                </table>
            </div>
            ${masterText}
            <div style="margin-top:50px; text-align:right;"><b>Dr. Radiólogo Jefe</b><br><small>Firma Electrónica Avanzada</small></div>
            <script>setTimeout(() => { window.print(); window.close(); }, 500);<\/script>
        </body></html>
    `);
    printWindow.document.close();
}