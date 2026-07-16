/* =========================================
   MÓDULO DE VALIDACIÓN (validation.js) - ENTERPRISE
   ========================================= */

let currentValidationData = [];
let currentValidationChain = null;
let currentValStudy = null;
let validationExamDateFilter = null;
let colorInformeGlobalValidation = "#111111";
let labInfoValidacion = { name: '', address: '', city: '', settings: {} };

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
    // Prioridad: laboratorio de la cita abierta (no mezclar otras sedes).
    if (currentValidationChain?.laboratory) {
        const lab = currentValidationChain.laboratory;
        labInfoValidacion = {
            name: lab.name || '',
            address: lab.address || '',
            city: lab.city || '',
            settings: lab.settings || {},
        };
        if (lab.settings?.colorInforme) {
            colorInformeGlobalValidation = lab.settings.colorInforme;
        }
    }
}

function renderCartaInformeValidacion() {
    if (!currentValidationChain || !currentValStudy || typeof risReportDocument === 'undefined') return;

    const study = {
        ...currentValStudy,
        reportText: $valRoot().find("#finalReportText").val() || currentValStudy.reportText || '',
    };
    const doc = risReportDocument.buildStudyDocument(currentValidationChain, study, labInfoValidacion);
    const e = (value) => (typeof risEscapeHtml === 'function' ? risEscapeHtml(value) : String(value ?? ''));
    const headerHtml = (doc.headerLines || [])
        .map((line) => `<div style="text-align:center;">${e(line)}</div>`)
        .join('');

    $valRoot().find("#reportLetterIntro").html(`
        <div style="font-family:'Times New Roman',Times,serif;font-size:12pt;line-height:1.45;color:${colorInformeGlobalValidation};">
            <div style="text-align:center;margin-bottom:18px;">${headerHtml}</div>
            <div style="margin-bottom:14px;">${e(doc.dateLine)}</div>
            <div style="margin-bottom:10px;">Estimado Doctor:</div>
            <div style="margin-bottom:14px;text-align:justify;">
                El examen realizado a su paciente Sr(a) ${e(doc.patientName)}, ha dado el siguiente resultado:
            </div>
            <div style="font-weight:bold;margin-bottom:10px;">${e(doc.examTitle)}</div>
        </div>
    `);

    // Firma del médico: va en el texto de transcripción (sin pie automático).
    $valRoot().find("#reportLetterFooter").empty();

    $valRoot().find("#finalReportText").css("color", colorInformeGlobalValidation);
}

function actualizarCabeceraInformeValidacion(chain, study) {
    actualizarDocClinicaValidacion();
    renderCartaInformeValidacion();
}

function actualizarHeaderPacienteValidacion(chain) {
    if (!chain) return;

    const patient = chain.patient || {};
    $valRoot().find("#valPatientName").text(formatearNombrePacienteValidacion(patient));
    $valRoot().find("#valPatientRut").text(patient.rut || '—');
    $valRoot().find("#valPatientAcc").text(chain.accessionNumber || '—');
}

function initValidation() {
    if (typeof risInitWorkflowExamDateFilter === "function") {
        validationExamDateFilter = risInitWorkflowExamDateFilter({
            moduleKey: "validation",
            rootSelector: "[data-ris-exam-date-filter]",
            onChange: () => cargarListaValidacion(),
        });
    }

    setupDocumentoValidacionUi();
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
                settings: lab.settings || {},
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
        const dateQuery = typeof risWorkflowExamDateQueryParam === "function" && validationExamDateFilter
            ? risWorkflowExamDateQueryParam(validationExamDateFilter.getState())
            : "";
        const url = `${API_URL}/radiologist/validations${dateQuery ? `?${dateQuery}` : ""}`;
        const response = await fetch(url, {
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
    const emptyHtml = '<div class="p-4 text-center text-muted"><i class="bi bi-check-circle fs-2 d-block mb-2 text-success"></i>Bandeja al día.</div>';

    $("#badgeParaFirma").text(currentValidationData.length);

    if (currentValidationData.length === 0) {
        lista.empty().append(emptyHtml);
        return;
    }

    const renderItem = (cadena) => {
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
        const fechaBadge = typeof risWorkflowExamDateBadgeHtml === "function"
            ? risWorkflowExamDateBadgeHtml(cadena)
            : "";

        return `
            <button type="button" class="list-group-item list-group-item-action ${isActive} p-3 border-bottom" onclick="abrirValidacion('${cadena.id}')">
                <div class="d-flex justify-content-between align-items-start gap-1 mb-1">
                    <strong class="text-truncate">${typeof risEscapeHtml === "function" ? risEscapeHtml(nombreLista) : nombreLista}</strong>
                    ${fechaBadge}
                </div>
                <div class="small ${mutedColor} mb-2">A.N.: ${cadena.accessionNumber}</div>
                <div class="small fw-bold ${textColor} text-truncate"><i class="bi bi-file-medical me-1"></i>${typeof risEscapeHtml === "function" ? risEscapeHtml(nombresExamenes) : nombresExamenes}</div>
            </button>
        `;
    };

    if (typeof risRenderWorkflowInboxGrouped === "function") {
        risRenderWorkflowInboxGrouped(lista, currentValidationData, renderItem, emptyHtml);
        return;
    }

    lista.empty();
    currentValidationData.forEach((cadena) => lista.append(renderItem(cadena)));
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

    if (!labInfoValidacion.name && !currentValidationChain.laboratory) {
        cargarAjustesVisualesValidacion().finally(() => {
            if (currentValidationChain?.id === citaId) {
                actualizarHeaderPacienteValidacion(currentValidationChain);
                actualizarCabeceraInformeValidacion(currentValidationChain, currentValStudy || studies[0] || null);
            }
        });
    }

    actualizarHeaderPacienteValidacion(currentValidationChain);

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
    const reportText = (currentValStudy.reportText || "").trim();
    const hasDoc = !!(currentValStudy.reportDocumentUrl || currentValStudy.reportDocumentPath);
    $valRoot().find("#finalReportText")
        .val(
            reportText
            || (hasDoc
                ? "Informe adjunto como documento (sin texto de transcripción)."
                : "Sin texto de informe registrado. Devuelva a transcripción para completar el dictado.")
        )
        .prop("disabled", true);

    const $docBox = $valRoot().find("#documentoValidacionAdjunto");
    const $docUpload = $valRoot().find("#documentoValidacionSubir");
    if ($docBox.length) {
        if (hasDoc && currentValStudy.reportDocumentUrl) {
            $docBox.removeClass("d-none");
            $docUpload.addClass("d-none");
            $valRoot().find("#linkDocumentoValidacion").attr("href", currentValStudy.reportDocumentUrl);
        } else {
            $docBox.addClass("d-none");
            $valRoot().find("#linkDocumentoValidacion").attr("href", "#");
            $docUpload.removeClass("d-none");
        }
    }

    renderCartaInformeValidacion();
    $valRoot().find("#finalReportText").removeClass("border border-warning border-2 bg-warning-subtle shadow-sm");
    $valRoot().find("#btnEditarValidacion").html('<i class="bi bi-pencil-square me-1"></i> CORREGIR TYPO').removeClass("btn-warning").addClass("btn-outline-warning");
}

function setupDocumentoValidacionUi() {
    const root = $valRoot();
    root.find("#btnReemplazarDocumentoVal, #btnSubirDocumentoVal")
        .off("click.risDocVal")
        .on("click.risDocVal", function () {
            if (!currentValidationChain || !currentValStudy) {
                if (typeof showToast === "function") showToast("Seleccione un examen primero.", "warning");
                return;
            }
            root.find("#inputDocumentoValidacion").val("").trigger("click");
        });

    root.find("#inputDocumentoValidacion")
        .off("change.risDocVal")
        .on("change.risDocVal", async function () {
            const file = this.files && this.files[0];
            if (!file) return;
            await subirDocumentoInformeValidacion(file);
            $(this).val("");
        });

    root.find("#btnQuitarDocumentoVal")
        .off("click.risDocVal")
        .on("click.risDocVal", function () {
            quitarDocumentoInformeValidacion();
        });
}

async function subirDocumentoInformeValidacion(file) {
    if (!currentValidationChain || !currentValStudy) return;

    const maxBytes = 20 * 1024 * 1024;
    if (file.size > maxBytes) {
        if (typeof showToast === "function") showToast("El archivo supera 20 MB.", "warning");
        return;
    }

    const token = localStorage.getItem("ris_token");
    const labId = localStorage.getItem("ris_lab_id");

    try {
        if (typeof showToast === "function") showToast("Subiendo documento corregido...", "info");
        const formData = new FormData();
        formData.append("study_id", currentValStudy.study_id);
        formData.append("document", file, file.name);

        const response = await fetch(
            `${API_URL}/transcription/appointments/${currentValidationChain.id}/report-document`,
            {
                method: "POST",
                headers: { Authorization: `Bearer ${token}`, "X-Lab-Id": labId },
                body: formData,
            }
        );
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || "No se pudo subir el documento.");
        }

        currentValStudy.reportDocumentPath = data.path || null;
        currentValStudy.reportDocumentUrl = data.url || null;
        if (data.reportText) {
            currentValStudy.reportText = data.reportText;
            $valRoot().find("#finalReportText").val(data.reportText);
        }
        cargarEstudioValidacion(currentValStudy.study_id);
        if (typeof showToast === "function") {
            showToast("Documento de informe actualizado (corrección).", "success");
        }
    } catch (e) {
        console.error(e);
        if (typeof showToast === "function") showToast(e.message || "Error al subir documento.", "danger");
    }
}

async function quitarDocumentoInformeValidacion() {
    if (!currentValidationChain || !currentValStudy) return;
    if (!(currentValStudy.reportDocumentUrl || currentValStudy.reportDocumentPath)) return;

    const ok = typeof showConfirm === "function"
        ? await showConfirm("¿Quitar el documento adjunto de este examen? Podrá subir uno corregido después.", {
            title: "Quitar documento",
            confirmText: "Quitar",
        })
        : window.confirm("¿Quitar el documento adjunto?");
    if (!ok) return;

    const token = localStorage.getItem("ris_token");
    const labId = localStorage.getItem("ris_lab_id");

    try {
        const response = await fetch(
            `${API_URL}/transcription/appointments/${currentValidationChain.id}/report-document?study_id=${encodeURIComponent(currentValStudy.study_id)}`,
            {
                method: "DELETE",
                headers: typeof risBuildAuthHeaders === "function"
                    ? risBuildAuthHeaders()
                    : { Authorization: `Bearer ${token}`, "X-Lab-Id": labId },
            }
        );
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || "No se pudo quitar el documento.");
        }
        currentValStudy.reportDocumentPath = null;
        currentValStudy.reportDocumentUrl = null;
        cargarEstudioValidacion(currentValStudy.study_id);
        if (typeof showToast === "function") showToast("Documento quitado. Puede subir uno corregido.", "secondary");
    } catch (e) {
        console.error(e);
        if (typeof showToast === "function") showToast(e.message || "Error al quitar documento.", "danger");
    }
}

async function firmarInforme() {
    if (!currentValidationChain) return;

    if (!(await showConfirm("¿Firmar digitalmente TODOS los informes de esta cita? El paciente podrá descargarlos inmediatamente.", { title: "Firmar informes", confirmText: "Firmar" }))) return;

        const btn = $("#btnAprobar");

        try {
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Firmando...');

            if (currentValStudy) {
                currentValStudy.reportText = $valRoot().find("#finalReportText").val();
            }

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
    $valRoot().find("#reportLetterIntro, #reportLetterFooter").empty();
    $valRoot().find("#examenesValidacion").empty();
    $valRoot().find("#documentoValidacionAdjunto").addClass("d-none");
    $valRoot().find("#documentoValidacionSubir").addClass("d-none");
    $valRoot().find("#linkDocumentoValidacion").attr("href", "#");
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
    renderCartaInformeValidacion();

    const study = {
        ...currentValStudy,
        reportText: $valRoot().find("#finalReportText").val() || '',
    };
    const previewHost = document.createElement('div');
    previewHost.innerHTML = typeof risReportDocument !== 'undefined'
        ? risReportDocument.buildHtml(
            risReportDocument.buildStudyDocument(currentValidationChain, study, labInfoValidacion),
            colorInformeGlobalValidation
        )
        : $valRoot().find('#papelInforme').html();

    if (typeof html2pdf !== 'undefined') {
        html2pdf().set({
            margin: [12, 12, 12, 12],
            filename: `Informe_${currentValidationChain?.accessionNumber || 'preview'}.pdf`,
            html2canvas: { scale: 2 },
            jsPDF: { unit: 'mm', format: 'letter', orientation: 'portrait' },
        }).from(previewHost.firstElementChild || previewHost).save().then(() => {
            if (typeof hideLoader === 'function') hideLoader();
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