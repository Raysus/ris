/* =========================================
   MÓDULO DE ENTREGA DE RESULTADOS (entrega.js)
   ========================================= */

let currentDeliveryData = [];
let citaIdParaEntrega = null;
let colorInformeGlobalDelivery = "#333333";

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

        if (response.ok && data.success && data.data && data.data.settings && data.data.settings.colorInforme) {
            colorInformeGlobalDelivery = data.data.settings.colorInforme;
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

        const examenesStr = item.studies.map(s => s.exam).join("<br>");

        tbody.append(`
            <tr>
                <td>
                    <div class="fw-bold text-dark">${item.patient.lastName} ${item.patient.secondLastName || ''}, ${item.patient.name}</div>
                    <div class="small text-muted">RUT: ${item.patient.rut}</div>
                </td>
                <td class="font-monospace text-secondary small fw-bold">${item.accessionNumber}</td>
                <td class="small fw-bold text-primary">${examenesStr}</td>
                <td>${badgeEstado}</td>
                <td>${acciones}</td>
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

    await ejecutarLlamadaEntrega(citaIdParaEntrega, 'deliver', datosRecepcion);

    const modalElement = document.getElementById('modalEntrega');
    const modalInstance = bootstrap.Modal.getInstance(modalElement);
    if (modalInstance) modalInstance.hide();

    btn.prop('disabled', false).html('<i class="bi bi-check2-circle me-1"></i> Confirmar Entrega');
    citaIdParaEntrega = null;
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

        if (response.ok) {
            if (typeof showToast === 'function') showToast(action === 'deliver' ? "✅ Resultados entregados y registrados." : "⚠️ Entrega revertida.", action === 'deliver' ? "success" : "warning");
            cargarListaEntrega();
        } else {
            if (typeof showToast === 'function') showToast("Error al actualizar el estado en el servidor.", "danger");
        }
    } catch (e) {
        console.error(e);
        if (typeof showToast === 'function') showToast("Error de conexión al registrar la entrega.", "danger");
    }
}

// === FUNCIONES DE IMPRESIÓN Y AUDITORÍA ===
function imprimirComprobanteEntrega(citaId) {
    const baseItem = currentDeliveryData.find(c => String(c.id) === String(citaId));
    if (!baseItem) return;

    // 🔥 Registro de Auditoría de Impresión (Silencioso)
    registrarImpresionEnLog(citaId);

    const clinicName = "HealthTiCloud RIS";
    const clinicAddress = "Av. Principal 123, Ciudad";

    let informesHtml = "";
    baseItem.studies.forEach(study => {
        informesHtml += `
            <div style="margin-bottom: 25px;">
                <h4 style="color: #2c3e50; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 15px;">${study.exam}</h4>
                <div class="report-body">${study.reportText || 'Sin informe redactado.'}</div>
            </div>
        `;
    });

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
                .report-body { 
                    font-size: 14px; 
                    text-align: justify; 
                    white-space: pre-wrap; 
                    margin-bottom: 30px; 
                    color: ${colorInformeGlobalDelivery}; 
                }
                .firma-container { margin-top: 60px; text-align: right; }
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
                    <tr>
                        <td><b>Paciente:</b> ${baseItem.patient.name} ${baseItem.patient.lastName}</td>
                        <td><b>RUT:</b> ${baseItem.patient.rut}</td>
                    </tr>
                    <tr>
                        <td><b>Accession N°:</b> ${baseItem.accessionNumber}</td>
                        <td><b>Fecha Impresión:</b> ${new Date().toLocaleDateString('es-CL')}</td>
                    </tr>
                </table>
            </div>
            
            ${informesHtml}
            
           <div class="firma-container">
                <div class="firma">
                    ${baseItem.firmaUrl
            ? `<img src="${baseItem.firmaUrl}" style="max-height: 80px; max-width: 200px; margin-bottom: 5px;"><br>`
            : `<br><br><br>` // Espacio en blanco si no tiene firma digitalizada
        }
                    <b>Dr(a). ${baseItem.doctorName}</b><br>
                    <small>Firma Electrónica Avanzada</small><br>
                    <small style="color: #7f8c8d;">Firmado el: ${baseItem.signatureDate || new Date().toLocaleDateString('es-CL')}</small>
                </div>
            </div>
            <script>setTimeout(() => { window.print(); window.close(); }, 500);<\/script>
        </body></html>
    `);
    printWindow.document.close();
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