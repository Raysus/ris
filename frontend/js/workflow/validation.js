/* =========================================
   MÓDULO DE VALIDACIÓN (validation.js)
   ========================================= */

let currentValidationData = [];
let currentValidationChain = null;
let colorInformeGlobalValidation = "#333333";

function initValidation() {
    cargarAjustesVisualesValidacion();
    cargarListaValidacion();
    setInterval(cargarListaValidacion, 30000);
}
async function cargarAjustesVisualesValidacion() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    try {
        const response = await fetch(`${API_URL}/settings`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();

        if (response.ok && data.success && data.data && data.data.settings && data.data.settings.colorInforme) {
            colorInformeGlobalValidation = data.data.settings.colorInforme;
            $("#finalReportText").css("color", colorInformeGlobalValidation);
        }
    } catch (e) { console.error("Error cargando ajustes visuales:", e); }
}

async function cargarListaValidacion() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const lista = $("#validationStudies");

    try {
        const response = await fetch(`${API_URL}/radiologist/validations`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();

        if (response.ok && data.success) {
            currentValidationData = data.data;
            renderValidationStudies();
        }
    } catch (e) {
        console.error("Error cargando validaciones:", e);
        lista.html('<div class="p-4 text-center text-danger"><i class="bi bi-wifi-off fs-2 d-block mb-2"></i>Error de conexión</div>');
    }
}

function renderValidationStudies() {
    const lista = $("#validationStudies");
    lista.empty();

    $("#badgeParaFirma").text(currentValidationData.length);

    if (currentValidationData.length === 0) {
        return lista.append('<div class="p-4 text-center text-muted"><i class="bi bi-check2-all fs-2 d-block mb-2 text-success"></i>Bandeja vacía. Todo firmado.</div>');
    }

    currentValidationData.forEach(cadena => {
        const isActive = currentValidationChain && currentValidationChain.id === cadena.id ? 'active bg-primary text-white border-primary' : '';
        const examenesStr = cadena.studies.map(s => s.exam).join(" + ");

        lista.append(`
            <button type="button" class="list-group-item list-group-item-action ${isActive} p-3 border-bottom" onclick="cargarValidacion('${cadena.id}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-truncate">${cadena.patient.lastName}, ${cadena.patient.name}</strong>
                </div>
                <div class="small fw-bold ${isActive ? 'text-white' : 'text-primary'} text-truncate"><i class="bi bi-file-text me-1"></i>${examenesStr}</div>
            </button>
        `);
    });
}

function cargarValidacion(citaId) {
    currentValidationChain = currentValidationData.find(c => String(c.id) === String(citaId));
    if (!currentValidationChain) return;

    renderValidationStudies();

    $("#infoPacienteValidacion").addClass("d-none");
    $("#docHeader, #firmaFalsa").removeClass("d-none");

    const p = currentValidationChain.patient;
    $("#docPaciente").text(`${p.name} ${p.lastName} ${p.secondLastName || ''}`);
    $("#docRut").text(p.rut);

    $("#docEdad").text(p.age ? `${p.age} años` : 'No especificada');

    let fechaTexto = 'No registrada';
    if (currentValidationChain.start_time) {
        const fechaObj = new Date(currentValidationChain.start_time);
        if (!isNaN(fechaObj)) fechaTexto = fechaObj.toLocaleDateString('es-CL');
    }
    $("#docFecha").text(fechaTexto);
    $("#docDerivante").text(currentValidationChain.referringDoctorName || 'No indicado');

    $("#docIdCita").text(`Accession Global: ${currentValidationChain.accessionNumber}`);

    const nombreFirma = currentValidationChain.destinationDoctorName
        ? `Dr(a). ${currentValidationChain.destinationDoctorName}`
        : "Dr. Radiólogo General";

    const firmaImagenHtml = currentValidationChain.firmaUrl
        ? `<img src="${currentValidationChain.firmaUrl}" style="max-height: 70px; max-width: 200px; margin-bottom: 5px; display: block; margin-left: auto; margin-right: auto;"><br>`
        : ``;

    $("#firmaNombre").html(`${firmaImagenHtml}<b>${nombreFirma}</b>`);

    let tabsHtml = '<div class="d-flex gap-2 flex-wrap">';
    currentValidationChain.studies.forEach((study, index) => {
        tabsHtml += `<button id="tab-val-${study.study_id}" class="study-tab-btn btn btn-sm btn-outline-primary fw-bold shadow-sm" onclick="cargarEstudioValidacion('${study.study_id}')">
            <i class="bi bi-file-text me-1"></i>${study.exam}</button>`;
    });
    tabsHtml += '</div>';
    $("#examenesValidacion").html(tabsHtml);

    if (currentValidationChain.studies.length > 0) {
        cargarEstudioValidacion(currentValidationChain.studies[0].study_id);
    }

    $("#btnRechazar, #btnAprobar, #btnPreview").prop("disabled", false);
}

function cargarEstudioValidacion(studyId) {
    currentValStudy = currentValidationChain.studies.find(s => String(s.study_id) === String(studyId));

    $(".study-tab-btn").removeClass("bg-primary text-white").addClass("btn-outline-primary");
    $(`#tab-val-${studyId}`).removeClass("btn-outline-primary").addClass("bg-primary text-white");

    $("#docExamen").text(currentValStudy.exam);
    $("#finalReportText").val(currentValStudy.reportText || "").prop("disabled", false);
}

async function firmarInforme() {
    if (!currentValidationChain) return;

    if (confirm("¿Confirmas que TODOS los informes están correctos y procedes a firmarlos digitalmente?")) {
        const token = localStorage.getItem('ris_token');
        const labId = localStorage.getItem('ris_lab_id');
        const btn = $("#btnAprobar");

        try {
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Firmando...');

            const paqueteInformes = currentValidationChain.studies.map(s => ({
                id: s.study_id,
                text: s.reportText
            }));

            const response = await fetch(`${API_URL}/radiologist/appointments/${currentValidationChain.id}/sign`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
                body: JSON.stringify({
                    reports: paqueteInformes,
                    dictation_method: 'transcripcion_revisada'
                })
            });

            if (response.ok) {
                showToast("✅ Informes firmados digitalmente y liberados.", "success");
                limpiarPantallaValidacion();
                cargarListaValidacion();
            } else {
                throw new Error("Error al firmar");
            }
        } catch (e) {
            showToast("❌ Error en el servidor al firmar.", "danger");
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-pen-fill me-1"></i> APROBAR Y FIRMAR INFORME');
        }
    }
}

async function rechazarInforme() {
    if (!currentValidationChain) return;

    const motivo = prompt("Indique a la secretaria las correcciones que debe realizar al informe:");

    if (motivo === null) return;
    if (motivo.trim() === "") {
        return showToast("⚠️ Debe ingresar un motivo para poder rechazarlo.", "warning");
    }

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const btn = $("#btnRechazar");

    try {
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Devolviendo...');

        console.log("Enviando petición a la Cita ID:", currentValidationChain.id);

        const response = await fetch(`${API_URL}/radiologist/appointments/${currentValidationChain.id}/reject-transcription`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'Authorization': `Bearer ${token}`,
                'X-Lab-Id': labId
            },
            body: JSON.stringify({ reason: motivo.trim() })
        });

        if (response.ok) {
            showToast("⚠️ Informe devuelto a la Bandeja de Transcripción.", "warning");
            limpiarPantallaValidacion();
            cargarListaValidacion();
        } else {
            const errorData = await response.text();
            console.error("Error del backend:", errorData);
            throw new Error(`Error ${response.status}`);
        }
    } catch (e) {
        console.error("Error completo:", e);
        showToast("❌ Error al devolver. Revisa la consola (F12).", "danger");
    } finally {
        btn.prop('disabled', false).html('<i class="bi bi-x-circle me-1"></i> Rechazar y Devolver a Secretaria');
    }
}

function limpiarPantallaValidacion() {
    currentValidationChain = null;
    $("#infoPacienteValidacion").removeClass("d-none");
    $("#docHeader, #firmaFalsa").addClass("d-none");
    $("#examenesValidacion").empty();
    $("#finalReportText").val("").prop("disabled", true);
    $("#btnRechazar, #btnAprobar, #btnPreview").prop("disabled", true);
}

function generarVistaPrevia() {
    if (typeof showLoader === 'function') showLoader();
    $("#finalReportText").css({ "resize": "none", "overflow": "hidden", "height": "auto" });
    $("#finalReportText")[0].style.height = $("#finalReportText")[0].scrollHeight + "px";

    if (typeof html2pdf !== 'undefined') {
        html2pdf().set({ margin: [15, 15, 15, 15], filename: 'preview.pdf' }).from(document.getElementById('papelInforme')).save().then(() => {
            if (typeof hideLoader === 'function') hideLoader();
            $("#finalReportText").css({ "height": "100%" });
        });
    } else {
        if (typeof hideLoader === 'function') hideLoader();
        alert("Librería PDF no cargada.");
    }
}

$(document).ready(function () {
    initValidation();

    $(document).on("input", "#finalReportText", function () {
        if (currentValStudy) {
            currentValStudy.reportText = $(this).val();
        }
    });
});