/* =========================================
   MÓDULO DE VALIDACIÓN (validation.js) - ENTERPRISE
   ========================================= */

let currentValidationData = [];
let currentValidationChain = null;
let currentValStudy = null;
let colorInformeGlobalValidation = "#333333";

function initValidation() {
    cargarAjustesVisualesValidacion();
    cargarListaValidacion();
    setInterval(() => {
        if (currentValidationChain || currentValStudy || $("#finalReportText").is(":visible")) {
            console.log("🔄 Refresco de validación omitido: Validando informe.");
            return;
        }
        cargarListaValidacion();
    }, 30000);
}

async function cargarAjustesVisualesValidacion() {
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) return;
    try {
        const response = await fetch(`${API_URL}/settings`, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const data = await response.json();

        if (response.ok && data.success && data.data && data.data.settings && data.data.settings.colorInforme) {
            colorInformeGlobalValidation = data.data.settings.colorInforme;
            $("#finalReportText").css("color", colorInformeGlobalValidation);
        }
    } catch (e) { console.error("Error cargando ajustes visuales:", e); }
}

async function cargarListaValidacion() {
    const lista = $("#validationStudies");
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) {
        lista.html('<div class="alert alert-warning m-3">Seleccione una sede específica.</div>');
        return;
    }

    try {
        const response = await fetch(`${API_URL}/radiologist/validations`, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const data = await response.json();

        if (response.ok && data.success) {
            currentValidationData = Array.isArray(data.data) ? data.data : [];
            renderValidationStudies();
            return;
        }

        const msg = data?.message || `Error al cargar bandeja (${response.status})`;
        console.error("Error cargando validaciones:", msg, data);
        lista.html(`<div class="p-4 text-center text-danger"><i class="bi bi-exclamation-triangle fs-2 d-block mb-2"></i>${msg}</div>`);
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
        lista.append('<div class="p-4 text-center text-muted"><i class="bi bi-check-circle fs-2 d-block mb-2 text-success"></i>Bandeja al día.</div>');
        return;
    }

    currentValidationData.forEach(cadena => {
        const isActive = currentValidationChain && currentValidationChain.id === cadena.id ? 'active bg-primary text-white border-primary' : '';
        const textColor = isActive ? 'text-white' : 'text-primary';
        const mutedColor = isActive ? 'text-white-50' : 'text-muted';

        const estudios = Array.isArray(cadena.studies) ? cadena.studies : [];
        const nombresExamenes = estudios.map(s => s.exam).filter(Boolean).join(" + ") || 'Sin examen';

        lista.append(`
            <button type="button" class="list-group-item list-group-item-action ${isActive} p-3 border-bottom" onclick="abrirValidacion('${cadena.id}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-truncate">${cadena.patient.lastName} ${cadena.patient.secondLastName || ''}, ${cadena.patient.name}</strong>
                </div>
                <div class="small ${mutedColor} mb-2">A.N.: ${cadena.accessionNumber}</div>
                <div class="small fw-bold ${textColor} text-truncate"><i class="bi bi-file-medical me-1"></i>${nombresExamenes}</div>
            </button>
        `);
    });
}

function abrirValidacion(citaId) {
    currentValidationChain = currentValidationData.find(c => String(c.id) === String(citaId));
    if (!currentValidationChain) return;

    if (
        currentValidationChain.accessionNumber &&
        typeof prefetchPacsStudy === "function"
    ) {
        prefetchPacsStudy(
            currentValidationChain.accessionNumber,
            currentValidationChain.id
        ).then((pacs) => {
            if (pacs && currentValidationChain?.id === citaId) {
                applyPacsStudyToChain(currentValidationChain, pacs);
            }
        });
    }

    renderValidationStudies();

    $("#placeholderValidacion").addClass("d-none");
    $("#infoPacienteValidacion").removeClass("d-none");

    $("#valPatientName").text(`${currentValidationChain.patient.name} ${currentValidationChain.patient.lastName} ${currentValidationChain.patient.secondLastName || ''}`);
    $("#valPatientRut").text(currentValidationChain.patient.rut);
    $("#valPatientAcc").text(currentValidationChain.accessionNumber);

    $("#docHeader, #firmaFalsa").removeClass("d-none");

    if (currentValidationChain.firmaUrl) {
        $("#firmaNombre").html(`<img src="${currentValidationChain.firmaUrl}" style="max-height: 60px; max-width: 150px; margin-bottom: 5px;"><br>Dr(a). ${currentValidationChain.destinationDoctorName}`);
    } else {
        $("#firmaNombre").text(`Dr(a). ${currentValidationChain.destinationDoctorName || 'Radiólogo'}`);
    }

    let tabsHtml = '<div class="d-flex gap-2 flex-wrap mb-3">';
    currentValidationChain.studies.forEach((study, index) => {
        const btnClass = index === 0 ? 'bg-primary text-white' : 'btn-outline-primary';
        tabsHtml += `<button id="tab-val-${study.study_id}" class="study-tab-btn-val btn btn-sm ${btnClass} fw-bold shadow-sm" onclick="cargarEstudioValidacion('${study.study_id}')">
            <i class="bi bi-file-medical me-1"></i>${study.exam}</button>`;
    });
    tabsHtml += '</div>';
    $("#examenesValidacion").html(tabsHtml);

    if (currentValidationChain.studies.length > 0) {
        cargarEstudioValidacion(currentValidationChain.studies[0].study_id);
    }

    // === ACTIVAR BOTONES DE HERRAMIENTAS ENTERPRISE ===
    $("#toolbarValidacion").attr("style", "display: flex !important;");
    $("#btnRechazar, #btnAprobar, #btnPreview, #btnVisorPacsValidacion, #btnVisorOhifValidacion, #btnEditarValidacion").prop("disabled", false);
}

function cargarEstudioValidacion(studyId) {
    currentValStudy = currentValidationChain.studies.find(s => String(s.study_id) === String(studyId));

    $(".study-tab-btn-val").removeClass("bg-primary text-white").addClass("btn-outline-primary");
    $(`#tab-val-${studyId}`).removeClass("btn-outline-primary").addClass("bg-primary text-white");

    // Colocar texto y asegurar que esté deshabilitado por defecto
    $("#finalReportText").val(currentValStudy.reportText || "").prop("disabled", true);

    // Resetear estilos de edición si quedaron activos de otro examen
    $("#finalReportText").removeClass("border border-warning border-2 bg-warning-subtle shadow-sm");
    $("#btnEditarValidacion").html('<i class="bi bi-pencil-square me-1"></i> CORREGIR TYPO').removeClass("btn-warning").addClass("btn-outline-warning");
}

async function firmarInforme() {
    if (!currentValidationChain) return;

    if (!(await showConfirm("¿Firmar digitalmente TODOS los informes de esta cita? El paciente podrá descargarlos inmediatamente.", { title: "Firmar informes", confirmText: "Firmar" }))) return;

        const btn = $("#btnAprobar");

        try {
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Firmando...');

            const paqueteInformes = currentValidationChain.studies.map(s => ({
                id: s.study_id,
                text: s.reportText
            }));

            const response = await fetch(`${API_URL}/radiologist/appointments/${currentValidationChain.id}/sign`, {
                method: 'POST',
                headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders({ 'Content-Type': 'application/json' }) : {},
                body: JSON.stringify({
                    reports: paqueteInformes,
                    dictation_method: 'transcripcion_validada'
                })
            });

            if (response.ok) {
                if (typeof showToast === 'function') showToast("✅ Informes firmados y liberados.", "success");
                limpiarPantallaValidacion();
                cargarListaValidacion();
            } else {
                throw new Error("Error en servidor");
            }
        } catch (e) {
            if (typeof showToast === 'function') showToast("❌ Error al firmar", "danger");
        } finally {
            btn.prop('disabled', false).html('<i class="bi bi-pen-fill me-1"></i> APROBAR Y FIRMAR INFORME');
        }
}

async function rechazarInforme() {
    if (!currentValidationChain) return;

    const motivo = await showPrompt(
        "Indique el motivo por el cual devuelve este informe a la secretaria:",
        { title: "Devolver informe" }
    );
    if (!motivo) return;

    const btn = $("#btnRechazar");

    try {
        btn.prop('disabled', true).html('Devolviendo...');

        const response = await fetch(`${API_URL}/radiologist/appointments/${currentValidationChain.id}/reject-transcription`, {
            method: 'POST',
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders({ 'Content-Type': 'application/json' }) : {},
            body: JSON.stringify({ reason: motivo })
        });

        if (response.ok) {
            if (typeof showToast === 'function') showToast("Informe devuelto a la secretaria para corrección.", "warning");
            limpiarPantallaValidacion();
            cargarListaValidacion();
        }
    } catch (e) {
        if (typeof showToast === 'function') showToast("Error al devolver.", "danger");
    } finally {
        btn.prop('disabled', false).html('<i class="bi bi-x-circle me-1"></i> Rechazar y Devolver a transcripción');
    }
}

function limpiarPantallaValidacion() {
    currentValidationChain = null;
    currentValStudy = null;

    $("#placeholderValidacion").removeClass("d-none");
    $("#infoPacienteValidacion").addClass("d-none");
    $("#docHeader, #firmaFalsa").addClass("d-none");
    $("#examenesValidacion").empty();
    $("#finalReportText").val("").prop("disabled", true);

    // Desactivar herramientas Enterprise
    $("#toolbarValidacion").attr("style", "display: none !important;");
    $("#btnRechazar, #btnAprobar, #btnPreview, #btnVisorPacsValidacion, #btnVisorOhifValidacion, #btnEditarValidacion").prop("disabled", true);

    // Limpiar estilos si quedó editando
    $("#finalReportText").removeClass("border border-warning border-2 bg-warning-subtle shadow-sm");
    $("#btnEditarValidacion").html('<i class="bi bi-pencil-square me-1"></i> CORREGIR TYPO').removeClass("btn-warning").addClass("btn-outline-warning");

    renderValidationStudies();
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
        showAlert("Librería html2pdf no cargada.", "Error", "danger");
    }
}

// === HERRAMIENTAS ENTERPRISE ===

async function abrirVisorPACSValidacion() {
    if (!currentValidationChain) return;
    await abrirVisorPACS(currentValidationChain.accessionNumber, {
        chain: currentValidationChain,
    });
}

async function abrirVisorSoloOHIFValidacion() {
    if (!currentValidationChain) return;
    await abrirVisorSoloOHIF(currentValidationChain.accessionNumber, {
        chain: currentValidationChain,
    });
}

function habilitarEdicionValidacion() {
    const txt = $("#finalReportText");
    const btn = $("#btnEditarValidacion");

    if (txt.prop("disabled")) {
        // Habilitar edición
        txt.prop("disabled", false).focus();
        txt.addClass("border border-warning border-2 bg-warning-subtle shadow-sm");
        btn.html('<i class="bi bi-check2-circle me-1"></i> TERMINAR EDICIÓN').removeClass("btn-outline-warning").addClass("btn-warning");
        if (typeof showToast === 'function') showToast("Edición rápida habilitada. Puede corregir el texto.", "info");
    } else {
        // Bloquear de nuevo
        txt.prop("disabled", true);
        txt.removeClass("border border-warning border-2 bg-warning-subtle shadow-sm");
        btn.html('<i class="bi bi-pencil-square me-1"></i> CORREGIR TYPO').removeClass("btn-warning").addClass("btn-outline-warning");
    }
}

// Guardar los cambios del textarea en memoria mientras se escribe
$(document).on("input", "#finalReportText", function () {
    if (currentValStudy) {
        currentValStudy.reportText = $(this).val();
    }
});

window.initValidation = initValidation;
window.abrirValidacion = abrirValidacion;
window.cargarListaValidacion = cargarListaValidacion;
window.firmarInforme = firmarInforme;
window.rechazarInforme = rechazarInforme;