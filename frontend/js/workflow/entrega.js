/* =========================================
   MÓDULO DE ENTREGA DE RESULTADOS
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
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    try {
        const response = await fetch(`${API_URL}/settings`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();

        if (response.ok && data.success && data.data && data.data.settings && data.data.settings.colorInforme) {
            colorInformeGlobalDelivery = data.data.settings.colorInforme;
        }
    } catch (e) { console.error("Error cargando ajustes visuales:", e); }
}

async function cargarListaEntrega() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const tbody = $("#tablaEntrega tbody");

    try {
        const response = await fetch(`${API_URL}/delivery/studies`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
    const search = $("#searchEntrega").val() ? $("#searchEntrega").val().toLowerCase() : "";
    const filtroEstado = $("#filtroEstadoEntrega").val();

    tbody.empty();

    let lista = currentDeliveryData;

    if (filtroEstado !== 'todos') {
        lista = lista.filter(c => c.status === filtroEstado);
    }
    if (search !== "") {
        lista = lista.filter(c => c.patient.rut.toLowerCase().includes(search) || c.patient.name.toLowerCase().includes(search) || c.patient.lastName.toLowerCase().includes(search));
    }

    if (lista.length === 0) return tbody.append('<tr><td colspan="6" class="text-center p-5 text-muted">No hay informes en esta bandeja.</td></tr>');

    lista.forEach(cadena => {
        const isEntregado = cadena.status === 'entregado';

        let badge = `<span class="badge bg-primary-subtle text-primary border border-primary">Pendiente</span>`;

        if (isEntregado) {
            const receptor = cadena.receiver_name || `${cadena.patient.name} ${cadena.patient.lastName}`;
            const relacion = cadena.relationship || 'Mismo Paciente';

            badge = `
                <span class="badge bg-success-subtle text-success border border-success mb-1">Entregado</span>
                <div class="small fw-bold text-dark mt-1" style="font-size: 0.75rem;">
                    <i class="bi bi-person-check-fill text-success me-1"></i>A: ${receptor}
                </div>
                <div class="text-muted" style="font-size: 0.65rem;">(${relacion})</div>
            `;
        }

        const examsStr = cadena.studies.map(e => `<span class="d-block"><i class="bi bi-check2 text-success me-1"></i>${e.exam}</span>`).join("");

        tbody.append(`
            <tr class="${isEntregado ? 'bg-light opacity-75' : ''}">
                <td class="ps-4">
                    <div class="fw-bold text-dark">${cadena.patient.lastName}, ${cadena.patient.name}</div>
                    <small class="text-muted">A.N: ${cadena.accessionNumber}</small>
                </td>
                <td class="fw-bold text-secondary">${cadena.patient.rut}</td>
                <td class="small fw-bold text-dark">${examsStr}</td>
                <td class="small text-muted">${cadena.signatureDate}</td>
                <td>${badge}</td>
               <td class="text-center pe-4">
                    <div class="btn-group shadow-sm">
                        <button class="btn btn-sm btn-outline-danger fw-bold" onclick="imprimirInformeGlobal('${cadena.id}')" title="Imprimir Informe PDF"><i class="bi bi-file-pdf"></i></button>
                        
                        <button class="btn btn-sm btn-outline-primary fw-bold" onclick="imprimirEtiquetaCD('${cadena.id}')" title="Imprimir Etiqueta CD/Pendrive"><i class="bi bi-disc"></i></button>
                        
                        ${!isEntregado
                ? `<button class="btn btn-sm btn-success fw-bold px-3" onclick="cambiarEstadoEntrega('${cadena.id}', 'deliver')">Entregar</button>`
                : `<button class="btn btn-sm btn-secondary" onclick="cambiarEstadoEntrega('${cadena.id}', 'revert')"><i class="bi bi-arrow-counterclockwise"></i></button>`}
                    </div>
                </td>
            </tr>
        `);
    });
}

function imprimirEtiquetaCD(citaId) {
    const baseItem = currentDeliveryData.find(c => String(c.id) === String(citaId));
    if (!baseItem) return;

    const clinicName = "Centro Médico RIS PRO";
    const fecha = new Date().toLocaleDateString('es-CL');
    const examsStr = baseItem.studies.map(s => s.exam).join(" + ");

    const printWindow = window.open('', '_blank', 'width=600,height=600');

    printWindow.document.write(`
        <html>
        <head>
            <title>Etiqueta CD - ${baseItem.patient.rut}</title>
            <style>
                body { 
                    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; 
                    margin: 0; 
                    padding: 0; 
                    display: flex; 
                    justify-content: center; 
                    align-items: center; 
                    background-color: #fff;
                }
                .cd-label {
                    width: 120mm;
                    height: 120mm;
                    border: 1px dashed #ccc; /* Guía de recorte, no sale fuerte en la impresión final */
                    border-radius: 50%; /* Diseño circular clásico de CD */
                    box-sizing: border-box;
                    text-align: center;
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                    align-items: center;
                    padding: 20mm;
                    position: relative;
                }
                /* Círculo central transparente del CD */
                .cd-label::after {
                    content: '';
                    position: absolute;
                    width: 15mm;
                    height: 15mm;
                    border: 1px solid #ccc;
                    border-radius: 50%;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                }
                h2 { margin: 0 0 15px 0; font-size: 16px; color: #000; text-transform: uppercase; letter-spacing: 1px;}
                .patient-name { font-size: 16px; font-weight: bold; margin-bottom: 8px; text-transform: uppercase; }
                .data-row { font-size: 12px; margin-bottom: 4px; color: #333; }
                .exams-box { 
                    margin-top: 15px; 
                    font-size: 11px; 
                    font-weight: bold;
                    border-top: 1px solid #000; 
                    border-bottom: 1px solid #000; 
                    padding: 5px 0;
                    width: 100%;
                }
                @media print {
                    .cd-label { border: none; } /* Ocultar el borde al imprimir si usan papel precortado */
                    .cd-label::after { display: none; } /* Ocultar el hoyo central al imprimir */
                }
            </style>
        </head>
        <body>
            <div class="cd-label">
                <h2>${clinicName}</h2>
                <div class="patient-name">${baseItem.patient.name} ${baseItem.patient.lastName}</div>
                <div class="data-row"><b>RUT:</b> ${baseItem.patient.rut}</div>
                <div class="data-row"><b>N° Orden (A.N):</b> ${baseItem.accessionNumber}</div>
                <div class="data-row"><b>Fecha:</b> ${fecha}</div>
                
                <div class="exams-box">
                    IMÁGENES DICOM<br>
                    <span style="font-weight: normal; font-size: 10px;">${examsStr}</span>
                </div>
            </div>
            <script>setTimeout(() => { window.print(); window.close(); }, 500);<\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

function cambiarEstadoEntrega(id, action) {
    if (action === 'revert') {
        if (confirm("¿Deshacer entrega y volver a bandeja de pendientes?")) {
            ejecutarLlamadaEntrega(id, 'revert', {});
        }
        return;
    }

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

async function procesarEntrega() {
    if (!citaIdParaEntrega) return;

    const datosRecepcion = {
        receiver_rut: $("#entregaRut").val().trim(),
        receiver_name: $("#entregaNombre").val().trim(),
        relationship: $("#entregaRelacion").val(),
        delivery_method: $("#entregaMetodo").val()
    };

    if (!datosRecepcion.receiver_rut || !datosRecepcion.receiver_name) {
        return showToast("Debe ingresar el RUT y Nombre de quien retira los exámenes.", "warning");
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
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');

    try {
        const response = await fetch(`${API_URL}/delivery/appointments/${id}/${action}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`,
                'X-Lab-Id': labId
            },
            body: JSON.stringify(bodyData)
        });

        if (response.ok) {
            showToast(action === 'deliver' ? "✅ Resultados entregados y registrados." : "⚠️ Entrega revertida.", action === 'deliver' ? "success" : "warning");
            cargarListaEntrega();
        } else {
            showToast("Error al actualizar el estado en el servidor.", "danger");
        }
    } catch (e) {
        console.error(e);
        showToast("Error de conexión al registrar la entrega.", "danger");
    }
}


function imprimirInformeGlobal(citaId) {
    const baseItem = currentDeliveryData.find(c => String(c.id) === String(citaId));
    if (!baseItem) return;

    const clinicName = "Centro Médico RIS PRO";
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
                    <small style="color: #7f8c8d;">Firmado el: ${baseItem.signatureDate}</small>
                </div>
            </div>
            <script>setTimeout(() => { window.print(); window.close(); }, 500);<\/script>
        </body></html>
    `);
    printWindow.document.close();
}

$(document).ready(function () {
    initEntrega();
});