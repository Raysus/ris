/* =========================================
   MÓDULO DE ENTREGA DE RESULTADOS (entrega.js)
   ========================================= */

let currentDeliveryData = [];
let citaIdParaEntrega = null;
let colorInformeGlobalDelivery = "#111111";
let labInfoEntrega = { name: '', address: '', city: '', settings: {} };

function escapeHtmlEntrega(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function formatExamenesEntrega(studies) {
    return (studies || []).map(s => {
        const exam = escapeHtmlEntrega(s.exam || 'Sin nombre');
        return `<div class="entrega-exam-item" title="${exam}">${exam}</div>`;
    }).join('');
}

function initEntrega() {
    cargarAjustesVisualesEntrega();
    cargarListaEntrega();
    setInterval(cargarListaEntrega, 30000);
}

async function cargarAjustesVisualesEntrega() {
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) return;
    try {
        const response = await fetch(`${API_URL}/settings`, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const data = await response.json();

        if (response.ok && data.success && data.data) {
            if (data.data.settings && data.data.settings.colorInforme) {
                colorInformeGlobalDelivery = data.data.settings.colorInforme;
            }
            labInfoEntrega = {
                name: data.data.name || '',
                address: data.data.address || '',
                city: data.data.city || '',
                settings: data.data.settings || {},
            };
        }
    } catch (e) { console.error("Error cargando ajustes visuales:", e); }
}

async function cargarListaEntrega() {
    const tbody = $("#tablaEntrega tbody");
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) {
        tbody.html('<tr><td colspan="6" class="text-center text-warning p-5">Seleccione una sede específica.</td></tr>');
        return;
    }

    try {
        const response = await fetch(`${API_URL}/delivery/studies`, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const data = await response.json();

        if (response.ok && data.success) {
            currentDeliveryData = data.data;
            renderTablaEntrega();
        }
    } catch (e) {
        tbody.html('<tr><td colspan="6" class="text-center text-danger p-5"><i class="bi bi-wifi-off fs-2 d-block"></i> Error de conexión</td></tr>');
    }
}

function renderTablaEntrega() {
    const tbody = $("#tablaEntrega tbody");
    tbody.empty();

    const filtro = $("#filtroEstadoEntrega").val();
    const search = $("#searchEntrega").val().toLowerCase();

    const datosFiltrados = currentDeliveryData.filter(item => {
        const matchesStatus = (filtro === 'todos') || (item.status === filtro);
        const term = search.trim();
        const matchesSearch = term === '' ||
            item.patient.rut.toLowerCase().includes(term) ||
            item.patient.name.toLowerCase().includes(term) ||
            item.patient.lastName.toLowerCase().includes(term);
        return matchesStatus && matchesSearch;
    });

    if (datosFiltrados.length === 0) {
        tbody.append('<tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox fs-2 d-block mb-2"></i>No se encontraron informes.</td></tr>');
        return;
    }

    datosFiltrados.forEach(item => {
        let badgeEstado = item.status === 'entregado'
            ? '<span class="badge bg-success shadow-sm"><i class="bi bi-check-all me-1"></i>Entregado</span>'
            : '<span class="badge bg-warning text-dark shadow-sm"><i class="bi bi-clock me-1"></i>Pendiente</span>';

        // === BOTONES MEJORADOS (Email e Impresión) ===
        let btnEntregar = `<button class="btn btn-sm btn-primary fw-bold shadow-sm" onclick="abrirModalEntrega('${item.id}')" title="Entrega Física Presencial"><i class="bi bi-person-check me-1"></i> Entregar</button>`;
        let btnEmail = `<button class="btn btn-sm btn-info text-white fw-bold shadow-sm ms-1" onclick="enviarResultadosPorEmail('${item.id}')" title="Enviar resultados por correo al paciente"><i class="bi bi-envelope-at me-1"></i> Email</button>`;
        let btnImprimir = `<button class="btn btn-sm btn-outline-secondary fw-bold ms-1" onclick="imprimirComprobanteEntrega('${item.id}')" title="Imprimir copia física"><i class="bi bi-printer"></i></button>`;
        let btnRevertir = `<button class="btn btn-sm btn-outline-danger fw-bold ms-1" onclick="revertirEntrega('${item.id}')" title="Revertir estado a Pendiente"><i class="bi bi-arrow-counterclockwise"></i></button>`;

        let acciones = item.status === 'entregable'
            ? `${btnEntregar} ${btnEmail} ${btnImprimir}`
            : `${btnEmail} ${btnImprimir} ${btnRevertir}`;

        const nombrePaciente = escapeHtmlEntrega(
            `${item.patient.lastName} ${item.patient.secondLastName || ''}, ${item.patient.name}`.trim()
        );
        const rutPaciente = escapeHtmlEntrega(item.patient.rut);
        const accession = escapeHtmlEntrega(item.accessionNumber);
        const examenesHtml = formatExamenesEntrega(item.studies);

        tbody.append(`
            <tr>
                <td class="col-entrega-paciente">
                    <div class="fw-bold text-dark entrega-paciente-nombre" title="${nombrePaciente}">${nombrePaciente}</div>
                    <div class="small text-muted">RUT: ${rutPaciente}</div>
                </td>
                <td class="col-entrega-acc font-monospace text-secondary small fw-bold entrega-acc-cell" title="${accession}">${accession}</td>
                <td class="col-entrega-exams">${examenesHtml}</td>
                <td class="col-entrega-estado">${badgeEstado}</td>
                <td class="col-entrega-acciones text-center pe-4">${acciones}</td>
            </tr>
        `);
    });
}

// === FUNCIONES DE FLUJO DE ENTREGA ===
function abrirModalEntrega(id) {
    citaIdParaEntrega = id;
    const cadena = currentDeliveryData.find(c => String(c.id) === String(id));

    if (cadena) {
        $("#entregaRut").val(cadena.patient.rut);
        $("#entregaNombre").val(`${cadena.patient.name} ${cadena.patient.lastName}`);
        $("#entregaRelacion").val("Mismo Paciente");
    } else {
        $("#entregaRut, #entregaNombre").val("");
    }

    const modal = new bootstrap.Modal(document.getElementById('modalEntrega'));
    modal.show();
}

async function revertirEntrega(id) {
    if (!(await showConfirm("¿Deshacer entrega y volver a bandeja de pendientes?", { title: "Revertir entrega", dangerous: true }))) return;
    ejecutarLlamadaEntrega(id, 'revert', {});
}

async function procesarEntrega() {
    if (!citaIdParaEntrega) return;

    const datosRecepcion = {
        receiver_rut: $("#entregaRut").val().trim(),
        receiver_name: $("#entregaNombre").val().trim(),
        relationship: $("#entregaRelacion").val(),
        delivery_method: $("#entregaMetodo").val()
    };

    if (!datosRecepcion.receiver_rut || !datosRecepcion.receiver_name) {
        if (typeof showToast === 'function') showToast("Debe ingresar el RUT y Nombre de quien retira los exámenes.", "warning");
        return;
    }

    const btn = $("#btnConfirmarEntrega");
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Guardando...');

    const ok = await ejecutarLlamadaEntrega(citaIdParaEntrega, 'deliver', datosRecepcion);

    if (ok) {
        const modalElement = document.getElementById('modalEntrega');
        const modalInstance = bootstrap.Modal.getInstance(modalElement);
        if (modalInstance) modalInstance.hide();
        citaIdParaEntrega = null;
    }

    btn.prop('disabled', false).html('<i class="bi bi-check2-circle me-1"></i> Confirmar Entrega');
}

async function ejecutarLlamadaEntrega(id, action, bodyData = {}) {
    try {
        const response = await fetch(`${API_URL}/delivery/appointments/${id}/${action}`, {
            method: 'POST',
            headers: typeof risBuildAuthHeaders === 'function'
                ? risBuildAuthHeaders({ 'Content-Type': 'application/json' })
                : {},
            body: JSON.stringify(bodyData)
        });

        const data = await response.json().catch(() => ({}));

        if (response.ok && data.success !== false) {
            const msg = data.already_delivered
                ? "La entrega ya estaba registrada."
                : (action === 'deliver' ? "✅ Resultados entregados y registrados." : "⚠️ Entrega revertida.");
            if (typeof showToast === 'function') showToast(msg, action === 'deliver' ? "success" : "warning");
            cargarListaEntrega();
            return true;
        }

        const detalle = data.message
            || (data.errors ? Object.values(data.errors).flat().join(' ') : '')
            || `Error en el servidor (${response.status})`;
        if (typeof showToast === 'function') showToast(`❌ ${detalle}`, "danger");
        return false;
    } catch (e) {
        console.error(e);
        if (typeof showToast === 'function') showToast("Error de conexión al registrar la entrega.", "danger");
        return false;
    }
}

// === FUNCIONES DE IMPRESIÓN Y AUDITORÍA ===
function imprimirComprobanteEntrega(citaId) {
    const baseItem = currentDeliveryData.find(c => String(c.id) === String(citaId));
    if (!baseItem) return;

    registrarImpresionEnLog(citaId);

    const studies = baseItem.studies || [];
    const docs = studies
        .map((s) => s.reportDocumentUrl)
        .filter((url) => !!url);

    // Si hay documentos adjuntos (en lugar de carta generada), abrirlos para imprimir.
    if (docs.length) {
        docs.forEach((url, idx) => {
            setTimeout(() => window.open(url, '_blank', 'noopener'), idx * 250);
        });
        if (typeof showToast === 'function') {
            showToast(
                docs.length === 1
                    ? 'Abriendo documento de informe adjunto para imprimir.'
                    : `Abriendo ${docs.length} documentos de informe adjuntos.`,
                'info'
            );
        }
        return;
    }

    const labInfo = baseItem.laboratory || labInfoEntrega;
    const chain = {
        ...baseItem,
        start_time: baseItem.start_time || baseItem.signatureDate,
        destinationDoctorName: baseItem.doctorName || baseItem.destinationDoctorName,
        destinationDoctorInitials: baseItem.destinationDoctorInitials,
        destinationDoctorRegistration: baseItem.destinationDoctorRegistration,
    };

    let html;
    if (typeof risReportDocument !== 'undefined') {
        html = risReportDocument.buildPrintDocumentHtml(
            chain,
            studies,
            labInfo,
            colorInformeGlobalDelivery
        );
    } else {
        html = `<html><body><pre>${escapeHtmlEntrega(studies.map(s => s.reportText).join('\n\n'))}</pre></body></html>`;
    }

    const printWindow = window.open('', '_blank');
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.onload = () => {
        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 500);
    };
}

function imprimirEtiquetaCD(citaId) {
    const baseItem = currentDeliveryData.find(c => String(c.id) === String(citaId));
    if (!baseItem) return;

    const clinicName = "HealthTiCloud RIS";
    const fecha = new Date().toLocaleDateString('es-CL');
    const examsStr = baseItem.studies.map(s => s.exam).join(" + ");

    const printWindow = window.open('', '_blank', 'width=600,height=600');

    printWindow.document.write(`
        <html>
        <head>
            <title>Etiqueta CD - ${baseItem.patient.rut}</title>
            <style>
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; margin: 0; padding: 0; display: flex; justify-content: center; align-items: center; background-color: #fff; }
                .cd-label { width: 120mm; height: 120mm; border: 1px dashed #ccc; border-radius: 50%; box-sizing: border-box; text-align: center; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 20mm; position: relative; }
                .cd-label::after { content: ''; position: absolute; width: 15mm; height: 15mm; border: 1px solid #ccc; border-radius: 50%; top: 50%; left: 50%; transform: translate(-50%, -50%); }
                h2 { margin: 0 0 15px 0; font-size: 16px; color: #000; text-transform: uppercase; letter-spacing: 1px;}
                .patient-name { font-size: 16px; font-weight: bold; margin-bottom: 8px; text-transform: uppercase; }
                .data-row { font-size: 12px; margin-bottom: 4px; color: #333; }
                .exams-box { margin-top: 15px; font-size: 11px; font-weight: bold; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 5px 0; width: 100%; }
                @media print { .cd-label { border: none; } .cd-label::after { display: none; } }
            </style>
        </head>
        <body>
            <div class="cd-label">
                <h2>${clinicName}</h2>
                <div class="patient-name">${baseItem.patient.name} ${baseItem.patient.lastName}</div>
                <div class="data-row"><b>RUT:</b> ${baseItem.patient.rut}</div>
                <div class="data-row"><b>N° Orden (A.N):</b> ${baseItem.accessionNumber}</div>
                <div class="data-row"><b>Fecha:</b> ${fecha}</div>
                <div class="exams-box">IMÁGENES DICOM<br><span style="font-weight: normal; font-size: 10px;">${examsStr}</span></div>
            </div>
            <script>setTimeout(() => { window.print(); window.close(); }, 500);<\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

async function enviarResultadosPorEmail(id) {
    const item = currentDeliveryData.find(c => String(c.id) === String(id));
    if (!item) return;

    if (!(await showConfirm(`¿Desea enviar los informes médicos al correo registrado del paciente ${item.patient.name} ${item.patient.lastName}?`, { title: "Enviar por correo", confirmText: "Enviar" }))) return;

    try {
        const response = await fetch(`${API_URL}/delivery/appointments/${id}/email`, {
            method: 'POST',
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const data = await response.json();

        if (response.ok) {
            if (typeof showToast === 'function') showToast(`✅ ${data.message}`, "success");
            cargarListaEntrega();
        } else {
            if (typeof showToast === 'function') showToast(`❌ Error: ${data.message}`, "danger");
        }
    } catch (e) {
        if (typeof showToast === 'function') showToast("Error de conexión al intentar enviar el correo.", "danger");
    }
}

async function registrarImpresionEnLog(id) {
    try {
        await fetch(`${API_URL}/delivery/appointments/${id}/log-print`, {
            method: 'POST',
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
    } catch (e) { }
}

$(document).ready(function () {
    initEntrega();
});