/* =========================================
   MÓDULO DE VALIDACIÓN (validation.js) - ENTERPRISE
   ========================================= */

let currentValidationData = [];
let currentValidationChain = null;
let currentValStudy = null;
let colorInformeGlobalValidation = "#333333";
let labInfoValidacion = { name: '', address: '', city: '' };

function $valRoot() {
    return $("#appContent");
}

function formatearNombrePacienteValidacion(patient) {
    if (!patient) return 'Sin datos de paciente';
    return `${patient.name || ''} ${patient.lastName || ''} ${patient.secondLastName || ''}`.trim() || 'Sin nombre';
}

function formatearFechaValidacion(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('es-CL');
}

function actualizarDocClinicaValidacion() {
    const nombre = labInfoValidacion.name
        || localStorage.getItem('ris_lab_name')
        || 'HealthTiCloud RIS';
    const dirParts = [labInfoValidacion.address, labInfoValidacion.city].filter(Boolean);
    $valRoot().find("#docClinicaNombre").text(nombre);
    $valRoot().find("#docClinicaDireccion").html(
        dirParts.length
            ? `<i class="bi bi-geo-alt-fill me-1"></i>${dirParts.join(', ')}`
            : '<i class="bi bi-geo-alt-fill me-1"></i>Dirección del Centro'
    );
}

function actualizarCabeceraInformeValidacion(chain, study) {
    if (!chain) return;

    actualizarDocClinicaValidacion();

    const patient = chain.patient || {};
    $valRoot().find("#docPaciente").text(formatearNombrePacienteValidacion(patient));
    $valRoot().find("#docRut").text(patient.rut || '—');
    $valRoot().find("#docEdad").text(patient.age ?? '—');
    $valRoot().find("#docFecha").text(formatearFechaValidacion(chain.start_time));
    $valRoot().find("#docDerivante").text(chain.referringDoctorName || 'No registrado');

    const examLabel = study
        ? (study.subExam ? `${study.exam} - ${study.subExam}` : (study.exam || '—'))
        : '—';
    $valRoot().find("#docExamen").text(examLabel);
    $valRoot().find("#docIdCita").text(
        chain.accessionNumber ? `Accession: ${chain.accessionNumber}` : `ID: ${chain.id}`
    );
}

function actualizarHeaderPacienteValidacion(chain) {
    if (!chain) return;

    const patient = chain.patient || {};
    $valRoot().find("#valPatientName").text(formatearNombrePacienteValidacion(patient));
    $valRoot().find("#valPatientRut").text(patient.rut || '—');
    $valRoot().find("#valPatientAcc").text(chain.accessionNumber || '—');
}

function initValidation() {
    cargarAjustesVisualesValidacion();
    cargarListaValidacion();
    setInterval(() => {
        if (currentValidationChain || currentValStudy) {
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

        if (response.ok && data.success && data.data) {
            const lab = data.data;
            if (lab.settings && lab.settings.colorInforme) {
                colorInformeGlobalValidation = lab.settings.colorInforme;
                $valRoot().find("#finalReportText").css("color", colorInformeGlobalValidation);
            }
            labInfoValidacion = {
                name: lab.name || localStorage.getItem('ris_lab_name') || '',
                address: lab.address || '',
                city: lab.city || '',
            };
            actualizarDocClinicaValidacion();
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
        const patient = cadena.patient || {};
        const apellidos = `${patient.lastName || ''} ${patient.secondLastName || ''}`.trim();
        const nombreLista = apellidos
            ? `${apellidos}, ${patient.name || 'Sin nombre'}`
            : (patient.name || 'Sin nombre');

        lista.append(`
            <button type="button" class="list-group-item list-group-item-action ${isActive} p-3 border-bottom" onclick="abrirValidacion('${cadena.id}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-truncate">${nombreLista}</strong>
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

    const studies = Array.isArray(currentValidationChain.studies)
        ? currentValidationChain.studies
        : [];

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

    $valRoot().find("#placeholderValidacion").addClass("d-none");
    $valRoot().find("#infoPacienteValidacion").removeClass("d-none");

    if (!labInfoValidacion.name) {
        cargarAjustesVisualesValidacion().finally(() => {
            if (currentValidationChain?.id === citaId) {
                actualizarHeaderPacienteValidacion(currentValidationChain);
                actualizarCabeceraInformeValidacion(currentValidationChain, currentValStudy || studies[0] || null);
            }
        });
    }

    actualizarHeaderPacienteValidacion(currentValidationChain);

    $valRoot().find("#docHeader, #firmaFalsa").removeClass("d-none");

    if (currentValidationChain.firmaUrl) {
        $valRoot().find("#firmaNombre").html(`<img src="${currentValidationChain.firmaUrl}" style="max-height: 60px; max-width: 150px; margin-bottom: 5px;"><br>Dr(a). ${currentValidationChain.destinationDoctorName}`);
    } else {
        $valRoot().find("#firmaNombre").text(`Dr(a). ${currentValidationChain.destinationDoctorName || 'Radiólogo'}`);
    }

    let tabsHtml = '<div class="d-flex gap-2 flex-wrap mb-3">';
    studies.forEach((study, index) => {
        const btnClass = index === 0 ? 'bg-primary text-white' : 'btn-outline-primary';
        tabsHtml += `<button id="tab-val-${study.study_id}" class="study-tab-btn-val btn btn-sm ${btnClass} fw-bold shadow-sm" onclick="cargarEstudioValidacion('${study.study_id}')">
            <i class="bi bi-file-medical me-1"></i>${study.exam}</button>`;
    });
    tabsHtml += '</div>';
    $valRoot().find("#examenesValidacion").html(tabsHtml);

    if (studies.length > 0) {
        cargarEstudioValidacion(studies[0].study_id);
    } else {
        actualizarCabeceraInformeValidacion(currentValidationChain, null);
    }

    // === ACTIVAR BOTONES DE HERRAMIENTAS ENTERPRISE ===
    $valRoot().find("#toolbarValidacion").attr("style", "display: flex !important;");
    $valRoot().find("#btnRechazar, #btnAprobar, #btnPreview, #btnVisorPacsValidacion, #btnVisorOhifValidacion, #btnEditarValidacion").prop("disabled", false);
}

function cargarEstudioValidacion(studyId) {
    if (!currentValidationChain) return;

    const studies = Array.isArray(currentValidationChain.studies)
        ? currentValidationChain.studies
        : [];
    currentValStudy = studies.find(s => String(s.study_id) === String(studyId));
    if (!currentValStudy) return;

    $valRoot().find(".study-tab-btn-val").removeClass("bg-primary text-white").addClass("btn-outline-primary");
    $valRoot().find(`#tab-val-${studyId}`).removeClass("btn-outline-primary").addClass("bg-primary text-white");

    actualizarCabeceraInformeValidacion(currentValidationChain, currentValStudy);

    // Colocar texto y asegurar que esté deshabilitado por defecto
    $valRoot().find("#finalReportText").val(currentValStudy.reportText || "").prop("disabled", true);

    // Resetear estilos de edición si quedaron activos de otro examen
    $valRoot().find("#finalReportText").removeClass("border border-warning border-2 bg-warning-subtle shadow-sm");
    $valRoot().find("#btnEditarValidacion").html('<i class="bi bi-pencil-square me-1"></i> CORREGIR TYPO').removeClass("btn-warning").addClass("btn-outline-warning");
}

async function firmarInforme() {
    if (!currentValidationChain) return;

    if (!(await showConfirm("¿Firmar digitalmente TODOS los informes de esta cita? El paciente podrá descargarlos inmediatamente.", { title: "Firmar informes", confirmText: "Firmar" }))) return;

        const btn = $("#btnAprobar");

        try {
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Firmando...');

            const paqueteInformes = (currentValidationChain.studies || [])
                .filter(s => s && s.study_id)
                .map(s => ({
                    id: String(s.study_id),
                    text: s.reportText ?? ''
                }));

            if (paqueteInformes.length === 0) {
                if (typeof showToast === 'function') showToast("No hay exámenes válidos para firmar.", "warning");
                return;
            }

            const response = await fetch(`${API_URL}/radiologist/appointments/${currentValidationChain.id}/sign`, {
                method: 'POST',
                headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders({ 'Content-Type': 'application/json' }) : {},
                body: JSON.stringify({
                    reports: paqueteInformes,
                    dictation_method: 'transcripcion_validada'
                })
            });

            const data = await response.json().catch(() => ({}));

            if (response.ok && data.success !== false) {
                if (typeof showToast === 'function') showToast("✅ Informes firmados y liberados.", "success");
                limpiarPantallaValidacion();
                cargarListaValidacion();
            } else {
                const detalle = data.message
                    || (data.errors ? Object.values(data.errors).flat().join(' ') : '')
                    || `Error en el servidor (${response.status})`;
                if (typeof showToast === 'function') showToast(`❌ Error al firmar: ${detalle}`, "danger");
            }
        } catch (e) {
            if (typeof showToast === 'function') showToast("❌ Error al firmar: no se pudo contactar al servidor.", "danger");
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

    $valRoot().find("#placeholderValidacion").removeClass("d-none");
    $valRoot().find("#infoPacienteValidacion").addClass("d-none");
    $valRoot().find("#docHeader, #firmaFalsa").addClass("d-none");
    $valRoot().find("#examenesValidacion").empty();
    $valRoot().find("#finalReportText").val("").prop("disabled", true);

    // Desactivar herramientas Enterprise
    $valRoot().find("#toolbarValidacion").attr("style", "display: none !important;");
    $valRoot().find("#btnRechazar, #btnAprobar, #btnPreview, #btnVisorPacsValidacion, #btnVisorOhifValidacion, #btnEditarValidacion").prop("disabled", true);

    // Limpiar estilos si quedó editando
    $valRoot().find("#finalReportText").removeClass("border border-warning border-2 bg-warning-subtle shadow-sm");
    $valRoot().find("#btnEditarValidacion").html('<i class="bi bi-pencil-square me-1"></i> CORREGIR TYPO').removeClass("btn-warning").addClass("btn-outline-warning");

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