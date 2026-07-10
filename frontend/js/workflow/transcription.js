/* =========================================
   MÓDULO DE TRANSCRIPCIÓN (transcription.js)
   ========================================= */

let currentTranscriptionData = [];
let currentTranscriptionChain = null;
let currentTransStudy = null;
let colorInformeGlobal = "#333333";
let transcriptionExamDateFilter = null;

// Declaración global para evitar el Uncaught ReferenceError
let autoSaveIntervalTrans = null;
/** Duración estimada cuando el WebM del dictado reporta Infinity (MediaRecorder). */
let audioDurationFallback = 0;

function initTranscription() {
    if (typeof risInitWorkflowExamDateFilter === "function") {
        transcriptionExamDateFilter = risInitWorkflowExamDateFilter({
            moduleKey: "transcription",
            rootSelector: "[data-ris-exam-date-filter]",
            onChange: () => cargarListaTranscripcion(),
        });
    }

    cargarAjustesVisuales();
    cargarListaTranscripcion();
    cargarPlantillasTranscripcion();
    setupKeyboardShortcuts();
    setupAudioListeners();
    setupTranscriptionSpeechMikeHooks();
    setupDocumentoTranscripcionUi();
    if (typeof risEnableTextFilePaste === "function") {
        risEnableTextFilePaste(["#textoTranscripcion"]);
    }
    if (typeof updateSiresaFootPedalUiHints === "function") {
        updateSiresaFootPedalUiHints();
    }
    if (typeof initSpeechMikeDictation === "function") {
        initSpeechMikeDictation();
    }

    setInterval(() => {
        if (currentTransStudy || currentTranscriptionChain || $("#textoTranscripcion").is(":focus")) {
            console.log("🔄 Refresco de transcripción omitido: Transcriptora trabajando en un registro.");
            return;
        }
        cargarListaTranscripcion();
    }, 30000);
}

async function cargarAjustesVisuales() {
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) return;
    try {
        const response = await fetch(`${API_URL}/settings`, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const data = await response.json();

        if (response.ok && data.success && data.data && data.data.settings && data.data.settings.colorInforme) {
            colorInformeGlobal = data.data.settings.colorInforme;
            $("#textoTranscripcion").css("color", colorInformeGlobal);
        }
    } catch (e) { console.error("Error cargando ajustes visuales:", e); }
}

async function cargarListaTranscripcion() {
    if (typeof risRequireConcreteLabId === 'function' && !risRequireConcreteLabId(false)) {
        $("#transcriptionStudies").html('<div class="alert alert-warning m-3">Seleccione una sede específica.</div>');
        return;
    }
    try {
        const dateQuery = typeof risWorkflowExamDateQueryParam === "function" && transcriptionExamDateFilter
            ? risWorkflowExamDateQueryParam(transcriptionExamDateFilter.getState())
            : "";
        const url = `${API_URL}/transcription/appointments${dateQuery ? `?${dateQuery}` : ""}`;
        const response = await fetch(url, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const result = await response.json();

        if (response.ok && result.success) {
            currentTranscriptionData = result.data;
            $("#contadorAudios").text(currentTranscriptionData.length);
            renderListaTranscripcion();
        }
    } catch (e) { console.error("Error cargando lista de transcripción:", e); }
}

function renderListaTranscripcion() {
    const contenedor = $("#listaTranscripcion");
    const emptyHtml = '<div class="p-4 text-center text-muted small"><i class="bi bi-check2-circle fs-3 d-block mb-2 text-success"></i>Bandeja al día. No hay dictados.</div>';

    if (currentTranscriptionData.length === 0) {
        contenedor.empty().append(emptyHtml);
        $("#contadorAudios").text(0);
        return;
    }

    $("#contadorAudios").text(currentTranscriptionData.length);

    const renderItem = (app) => {
        const selectedClass = (currentTranscriptionChain && currentTranscriptionChain.id === app.id) ? 'active bg-primary text-white' : '';
        const badgeAudio = app.hasAudio ? '<span class="badge bg-success small"><i class="bi bi-mic-fill"></i> Audio</span>' : '<span class="badge bg-secondary small">Sin Audio</span>';

        let badgeEstado = '';
        let claseBorde = 'border-start border-4 border-primary';

        if (app.needsReview && app.returnReason !== '') {
            claseBorde = 'border-start border-4 border-danger bg-danger-subtle';
            badgeEstado = `
                <div class="mt-2 small text-danger fw-bold">
                    <i class="bi bi-exclamation-triangle-fill"></i> Devuelto: ${risEscapeHtml(app.returnReason)}
                </div>`;
        } else {
            badgeEstado = `<div class="mt-2 small text-success opacity-75"><i class="bi bi-check-circle"></i> Dictado Nuevo</div>`;
        }

        const nombreCompleto = `${app.patient.name} ${app.patient.lastName} ${app.patient.secondLastName || ''}`.trim();
        const fechaBadge = typeof risWorkflowExamDateBadgeHtml === "function"
            ? risWorkflowExamDateBadgeHtml(app)
            : "";

        return `
            <button type="button" class="list-group-item list-group-item-action p-3 d-flex flex-column align-items-start gap-1 ${selectedClass} ${claseBorde}" onclick="abrirTranscripcion('${app.id}')">
                <div class="d-flex w-100 justify-content-between align-items-center gap-1 flex-wrap">
                    <h6 class="mb-0 fw-bold font-monospace text-truncate" style="max-width: 150px;">${risEscapeHtml(app.accessionNumber)}</h6>
                    <div class="d-flex gap-1 align-items-center">${fechaBadge} ${badgeAudio}</div>
                </div>
                <strong class="m-0 text-truncate w-100" style="font-size: 0.95rem;">${risEscapeHtml(nombreCompleto)}</strong>
                <div class="d-flex w-100 justify-content-between align-items-center mt-1 opacity-75 small">
                    <span>RUT: ${risEscapeHtml(app.patient.rut)}</span>
                    <span>Edad: ${app.patient.age}</span>
                </div>
                ${badgeEstado}
            </button>
        `;
    };

    if (typeof risRenderWorkflowInboxGrouped === "function") {
        risRenderWorkflowInboxGrouped(contenedor, currentTranscriptionData, renderItem, emptyHtml);
        return;
    }

    contenedor.empty();
    currentTranscriptionData.forEach((app) => contenedor.append(renderItem(app)));
}

function abrirTranscripcion(id) {
    const app = currentTranscriptionData.find(x => x.id == id);
    if (!app) return;

    currentTranscriptionChain = app;
    renderListaTranscripcion();

    // 💡 LÓGICA VISUAL: Alerta gigante si el paciente viene rechazado
    const retornoAlerta = (app.needsReview && app.returnReason !== '') ? `
        <div class="alert alert-danger py-2 px-3 mb-3 small shadow-sm d-flex align-items-center gap-2 border-danger border-2">
            <i class="bi bi-exclamation-octagon-fill fs-4"></i>
            <div><strong class="d-block">Devuelto por Validación Médica:</strong> ${risEscapeHtml(app.returnReason)}</div>
        </div>
    ` : '';

    const nombreCompleto = `${app.patient.name} ${app.patient.lastName} ${app.patient.secondLastName || ''}`.trim();

    $("#infoPacienteTranscripcion").html(`
        ${retornoAlerta}
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-1 fw-bold text-dark"><i class="bi bi-person-fill me-2 text-secondary"></i>${risEscapeHtml(nombreCompleto)}</h5>
                <span class="badge bg-dark font-monospace fs-6">${risEscapeHtml(app.accessionNumber)}</span>
                <span class="text-muted small ms-3"><b>RUT:</b> ${risEscapeHtml(app.patient.rut)}</span>
                <span class="text-muted small ms-3"><b>Edad:</b> ${app.patient.age}</span>
            </div>
            <div class="text-end">
                <small class="text-muted d-block font-monospace">ID Cita: ${app.id}</small>
            </div>
        </div>
    `);

    const contenedorExamenes = $("#listaExamenesTranscripcion");
    contenedorExamenes.empty();

    app.studies.forEach((study, idx) => {
        const subExamenLabel = study.subExam ? ` - <small class="opacity-75">${study.subExam}</small>` : '';
        const btn = $(`<button class="btn btn-sm btn-outline-dark fw-bold shadow-sm text-start"></button>`);
        btn.html(`<i class="bi bi-file-earmark-medical me-1"></i> ${risEscapeHtml(study.exam)} ${subExamenLabel}`);

        btn.on('click', () => {
            $("#listaExamenesTranscripcion button").removeClass("btn-dark text-white").addClass("btn-outline-dark");
            btn.removeClass("btn-outline-dark").addClass("btn-dark text-white");
            cargarEstudioTranscripcion(study.study_id);
        });
        contenedorExamenes.append(btn);

        if (idx === 0) btn.click();
    });

    $("#btnDevolverAudio").prop("disabled", false);
    $("#btnEnviarValidacion").prop("disabled", false);
    $("#btnPlantillaTrans").prop("disabled", false);
    $("#btnSubirDocumentoTrans").prop("disabled", false);
}

function persistTranscripcionActualEnMemoria() {
    if (!currentTransStudy) return;
    const txt = $("#textoTranscripcion").val();
    currentTransStudy.reportText = txt;
}

function construirPayloadInformesTranscripcion() {
    persistTranscripcionActualEnMemoria();
    return (currentTranscriptionChain?.studies || []).map(s => ({
        id: s.study_id,
        text: s.reportText || ''
    }));
}

function cargarEstudioTranscripcion(studyId) {
    if (!currentTranscriptionChain) return;

    persistTranscripcionActualEnMemoria();

    const study = currentTranscriptionChain.studies.find(s => s.study_id == studyId);
    if (!study) return;

    currentTransStudy = study;

    const txt = $("#textoTranscripcion");
    txt.prop("disabled", false).val(study.reportText || "");
    actualizarUiDocumentoTranscripcion(study);

    if (autoSaveIntervalTrans) {
        clearInterval(autoSaveIntervalTrans);
    }
    iniciarAutoguardadoTrans();

    const audioEl = document.getElementById("audioDictado");

    if (study.audioUrl) {
        resetAudioPlaybackState();
        audioEl.src = study.audioUrl;
        audioEl.load();

        $("#btnPlayPause").prop("disabled", false).removeClass("btn-secondary").addClass("btn-primary");
        $("#iconPlayPause").removeClass("bi-pause-fill").addClass("bi-play-fill");
        $("#audioProgress").val(0).prop("disabled", false);
        $("#timeCurrent").text("0:00");
        $("#timeTotal").text("--:--");
        setAudioSpeed(1.0);
    } else {
        resetAudioPlaybackState();
        audioEl.src = "";
        $("#btnPlayPause").prop("disabled", true).removeClass("btn-primary").addClass("btn-secondary");
        $("#iconPlayPause").removeClass("bi-pause-fill").addClass("bi-play-fill");
        $("#audioProgress").val(0).prop("disabled", true);
        $("#timeCurrent").text("0:00");
        $("#timeTotal").text("0:00");
    }
}

function resetAudioPlaybackState() {
    audioDurationFallback = 0;
}

function getAudioDurationSeconds(audio) {
    if (!audio) return null;
    const meta = audio.duration;
    if (Number.isFinite(meta) && meta > 0) {
        return meta;
    }
    if (audioDurationFallback > 0) {
        return audioDurationFallback;
    }
    return null;
}

function updateAudioTimeUI() {
    const audio = document.getElementById("audioDictado");
    if (!audio || !audio.src) return;

    const current = Number.isFinite(audio.currentTime) ? audio.currentTime : 0;
    const total = getAudioDurationSeconds(audio);

    $("#timeCurrent").text(formatTime(current));

    if (total) {
        $("#timeTotal").text(formatTime(total));
        $("#audioProgress").val(Math.min(100, Math.max(0, (current / total) * 100)));
    } else {
        $("#timeTotal").text("--:--");
    }
}

// === ASINCRONISMO Y ESCUCHADORES DEL AUDIO ===
function setupAudioListeners() {
    const audio = document.getElementById("audioDictado");
    if (!audio) return;

    audio.addEventListener("timeupdate", () => {
        if (Number.isFinite(audio.currentTime)) {
            audioDurationFallback = Math.max(audioDurationFallback, audio.currentTime + 0.1);
        }
        updateAudioTimeUI();
    });

    audio.addEventListener("loadedmetadata", () => updateAudioTimeUI());
    audio.addEventListener("durationchange", () => updateAudioTimeUI());
    audio.addEventListener("loadeddata", () => updateAudioTimeUI());

    audio.addEventListener("ended", () => {
        if (Number.isFinite(audio.currentTime)) {
            audioDurationFallback = Math.max(audioDurationFallback, audio.currentTime);
        }
        updateAudioTimeUI();
        $("#iconPlayPause").removeClass("bi-pause-fill").addClass("bi-play-fill");
    });

    audio.addEventListener("error", () => {
        if (typeof showToast === "function") {
            showToast("No se pudo cargar el archivo de audio.", "danger");
        }
        $("#timeTotal").text("--:--");
    });
}

function togglePlayPause() {
    const audio = document.getElementById("audioDictado");
    if (!audio || !audio.src) return;

    if (audio.paused) {
        audio.play();
        $("#iconPlayPause").removeClass("bi-play-fill").addClass("bi-pause-fill");
    } else {
        audio.pause();
        $("#iconPlayPause").removeClass("bi-pause-fill").addClass("bi-play-fill");
    }
}

function skipAudio(seconds) {
    const audio = document.getElementById("audioDictado");
    if (!audio || !audio.src) return;
    const total = getAudioDurationSeconds(audio);
    const next = audio.currentTime + seconds;
    audio.currentTime = total
        ? Math.max(0, Math.min(total, next))
        : Math.max(0, next);
    updateAudioTimeUI();
}

function seekAudio() {
    const audio = document.getElementById("audioDictado");
    if (!audio || !audio.src) return;
    const total = getAudioDurationSeconds(audio);
    if (!total) return;
    const porcentaje = $("#audioProgress").val();
    audio.currentTime = (porcentaje / 100) * total;
    updateAudioTimeUI();
}

function setAudioSpeed(speed) {
    const audio = document.getElementById("audioDictado");
    if (!audio) return;
    audio.playbackRate = speed;
    $("#speedIndicator").text(speed + "x");
}

function formatTime(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) return "0:00";
    const totalSecs = Math.floor(seconds);
    const m = Math.floor(totalSecs / 60);
    const s = totalSecs % 60;
    return `${m}:${s < 10 ? "0" : ""}${s}`;
}

// === CONTROL DE AUTOGUARDADO ===
function iniciarAutoguardadoTrans() {
    autoSaveIntervalTrans = setInterval(async () => {
        if (!currentTranscriptionChain || !currentTransStudy) return;

        const textActual = $("#textoTranscripcion").val();

        try {
            const payload = {
                reports: [{
                    id: currentTransStudy.study_id,
                    text: textActual
                }]
            };

            $("#autoSaveIndTrans").fadeIn().html('<i class="bi bi-arrow-repeat text-primary spinning"></i> Guardando...');

            const response = await fetch(`${API_URL}/transcription/appointments/${currentTranscriptionChain.id}/draft`, {
                method: 'POST',
                headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders({ 'Content-Type': 'application/json' }) : {},
                body: JSON.stringify(payload)
            });

            if (response.ok) {
                currentTransStudy.reportText = textActual;
                $("#autoSaveIndTrans").html('<i class="bi bi-cloud-arrow-up text-success me-1"></i>Guardado automáticamente').delay(2000).fadeOut();
            }
        } catch (e) {
            console.error("Error en auto-guardado automático de transcripción", e);
            $("#autoSaveIndTrans").html('<i class="bi bi-cloud-slash text-danger me-1"></i>Error al guardar');
        }
    }, 15000);
}

$(document).on("input", "#textoTranscripcion", function () {
    if (currentTransStudy) currentTransStudy.reportText = $(this).val();
});

async function enviarAValidacion() {
    if (!currentTranscriptionChain) return;

    const btn = $("#btnEnviarValidacion");
    const reports = construirPayloadInformesTranscripcion();

    if (reports.length === 0) {
        if (typeof showToast === 'function') showToast("No hay exámenes para enviar.", "warning");
        return;
    }

    const incompletos = (currentTranscriptionChain.studies || []).filter((s) => {
        const text = String(s.reportText || '').trim();
        return !text && !s.reportDocumentUrl && !s.reportDocumentPath;
    });
    if (incompletos.length) {
        if (typeof showToast === 'function') {
            showToast("Cada examen debe tener texto o un documento adjunto antes de enviar.", "warning");
        }
        return;
    }

    const payload = { reports };

    try {
        btn.prop('disabled', true).html('Enviando...');

        const response = await fetch(`${API_URL}/transcription/appointments/${currentTranscriptionChain.id}/validate`, {
            method: 'POST',
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders({ 'Content-Type': 'application/json' }) : {},
            body: JSON.stringify(payload)
        });

        const result = await response.json();

        if (response.ok && result.success) {
            limpiarPantallaTranscripcion();
            cargarListaTranscripcion();
        } else {
            throw new Error(result.message || "Error al validar");
        }
    } catch (e) {
        console.error("Error en enviarAValidacion:", e);
        showToast("Error al enviar: " + e.message, "danger");
    } finally {
        btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> ENVIAR A FIRMA...');
    }
}

async function devolverAudioAlMedico() {
    if (!currentTranscriptionChain) return;

    const motivo = await showPrompt(
        "Indique por qué devuelve este audio al Radiólogo (Ej: Audio inaudible, cortado, vacío):",
        { title: "Devolver audio" }
    );
    if (!motivo) return;

    const btn = $("#btnDevolverAudio");

    try {
        btn.prop('disabled', true).html('Devolviendo...');

        const response = await fetch(`${API_URL}/transcription/appointments/${currentTranscriptionChain.id}/return`, {
            method: 'POST',
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders({ 'Content-Type': 'application/json' }) : {},
            body: JSON.stringify({ reason: motivo })
        });

        if (response.ok) {
            if (typeof showToast === 'function') showToast("Audio devuelto a la bandeja del Radiólogo.", "warning");
            limpiarPantallaTranscripcion();
            cargarListaTranscripcion();
        } else {
            let data = {};
            try { data = await response.json(); } catch (e) { /* ignore */ }
            const msg = data.message || data.error || `Error ${response.status} al devolver al radiólogo.`;
            if (typeof showToast === 'function') showToast(msg, "danger");
        }
    } catch (e) {
        console.error(e);
        if (typeof showToast === 'function') showToast("Error al retornar flujo al médico.", "danger");
    } finally {
        btn.prop('disabled', false).html('<i class="bi bi-exclamation-triangle me-1"></i> Reportar Audio');
    }
}

function limpiarPantallaTranscripcion() {
    if (autoSaveIntervalTrans) {
        clearInterval(autoSaveIntervalTrans);
        autoSaveIntervalTrans = null;
    }

    const audioEl = document.getElementById("audioDictado");
    if (audioEl) audioEl.src = "";
    resetAudioPlaybackState();

    currentTranscriptionChain = null;
    currentTransStudy = null;

    $("#infoPacienteTranscripcion").html(`
        <div class="text-center text-muted p-4">
            <i class="bi bi-headphones fs-1 d-block mb-3"></i>
            Seleccione un dictado del panel izquierdo para comenzar a transcribir.
        </div>
    `);

    $("#listaExamenesTranscripcion").empty();
    $("#textoTranscripcion").val("").prop("disabled", true);
    $("#documentoTransAdjunto").addClass("d-none");
    $("#btnDevolverAudio").prop("disabled", true);
    $("#btnEnviarValidacion").prop("disabled", true);
    $("#btnPlantillaTrans").prop("disabled", true);
    $("#btnSubirDocumentoTrans").prop("disabled", true);
    $("#audioProgress").val(0).prop("disabled", true);
    $("#timeCurrent").text("0:00");
    $("#timeTotal").text("0:00");
}

function actualizarUiDocumentoTranscripcion(study) {
    const $box = $("#documentoTransAdjunto");
    const url = study?.reportDocumentUrl || null;
    if (!url) {
        $box.addClass("d-none");
        $("#linkDocumentoTrans").attr("href", "#");
        return;
    }
    $box.removeClass("d-none");
    $("#linkDocumentoTrans").attr("href", url);
}

function setupDocumentoTranscripcionUi() {
    $("#btnSubirDocumentoTrans")
        .off("click.risDocTrans")
        .on("click.risDocTrans", function () {
            if (!currentTranscriptionChain || !currentTransStudy) {
                if (typeof showToast === "function") {
                    showToast("Seleccione un dictado y un examen primero.", "warning");
                }
                return;
            }
            $("#inputDocumentoTrans").val("").trigger("click");
        });

    $("#inputDocumentoTrans")
        .off("change.risDocTrans")
        .on("change.risDocTrans", async function () {
            const file = this.files && this.files[0];
            if (!file) return;
            await subirDocumentoInformeTranscripcion(file);
            $(this).val("");
        });

    $("#btnQuitarDocumentoTrans")
        .off("click.risDocTrans")
        .on("click.risDocTrans", function () {
            quitarDocumentoInformeTranscripcion();
        });
}

async function subirDocumentoInformeTranscripcion(file) {
    if (!currentTranscriptionChain || !currentTransStudy) return;

    const maxBytes = 20 * 1024 * 1024;
    if (file.size > maxBytes) {
        if (typeof showToast === "function") showToast("El archivo supera 20 MB.", "warning");
        return;
    }

    const btn = $("#btnSubirDocumentoTrans");
    const original = btn.html();
    const token = localStorage.getItem("ris_token");
    const labId = localStorage.getItem("ris_lab_id");

    try {
        btn.prop("disabled", true).html('<span class="spinner-border spinner-border-sm"></span> Subiendo...');
        persistTranscripcionActualEnMemoria();

        const formData = new FormData();
        formData.append("study_id", currentTransStudy.study_id);
        formData.append("document", file, file.name);

        const response = await fetch(
            `${API_URL}/transcription/appointments/${currentTranscriptionChain.id}/report-document`,
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

        currentTransStudy.reportDocumentPath = data.path || null;
        currentTransStudy.reportDocumentUrl = data.url || null;
        if (data.reportText) {
            currentTransStudy.reportText = data.reportText;
            $("#textoTranscripcion").val(data.reportText);
        }
        actualizarUiDocumentoTranscripcion(currentTransStudy);
        if (typeof showToast === "function") {
            showToast("Documento de informe adjunto. Puede enviarlo a firma sin transcribir.", "success");
        }
    } catch (e) {
        console.error(e);
        if (typeof showToast === "function") showToast(e.message || "Error al subir documento.", "danger");
    } finally {
        btn.prop("disabled", false).html(original);
    }
}

async function quitarDocumentoInformeTranscripcion() {
    if (!currentTranscriptionChain || !currentTransStudy) return;
    if (!(currentTransStudy.reportDocumentUrl || currentTransStudy.reportDocumentPath)) return;

    const ok = typeof showConfirm === "function"
        ? await showConfirm("¿Quitar el documento adjunto de este examen?", {
            title: "Quitar documento",
            confirmText: "Quitar",
        })
        : window.confirm("¿Quitar el documento adjunto?");
    if (!ok) return;

    const token = localStorage.getItem("ris_token");
    const labId = localStorage.getItem("ris_lab_id");

    try {
        const response = await fetch(
            `${API_URL}/transcription/appointments/${currentTranscriptionChain.id}/report-document?study_id=${encodeURIComponent(currentTransStudy.study_id)}`,
            {
                method: "DELETE",
                headers: typeof risBuildAuthHeaders === "function"
                    ? risBuildAuthHeaders()
                    : {
                        Authorization: `Bearer ${token}`,
                        "X-Lab-Id": labId,
                    },
            }
        );
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || "No se pudo quitar el documento.");
        }
        currentTransStudy.reportDocumentPath = null;
        currentTransStudy.reportDocumentUrl = null;
        actualizarUiDocumentoTranscripcion(currentTransStudy);
        if (typeof showToast === "function") showToast("Documento quitado.", "secondary");
    } catch (e) {
        console.error(e);
        if (typeof showToast === "function") showToast(e.message || "Error al quitar documento.", "danger");
    }
}

// === PLANTILLAS DE TRANSCRIPCIÓN ===
async function cargarPlantillasTranscripcion() {
    try {
        const response = await fetch(`${API_URL}/templates`, {
            headers: typeof risBuildAuthHeaders === 'function' ? risBuildAuthHeaders() : {}
        });
        const data = await response.json();
        if (response.ok && data.success) {
            const dropdown = $("#dropdownPlantillasTrans");
            dropdown.empty();
            if (data.data.length === 0) {
                dropdown.append('<li><span class="dropdown-item text-muted">No hay plantillas creadas</span></li>');
                return;
            }
            data.data.forEach(t => {
                const item = $(`<li><a class="dropdown-item small" style="cursor:pointer;"><b>${t.title}</b> <small class="text-muted">(${t.exam_name || 'General'})</small></a></li>`);
                item.find('a').on('click', () => {
                    const txt = $("#textoTranscripcion");
                    const currentVal = txt.val();
                    txt.val(currentVal + (currentVal ? "\n" : "") + t.content).focus();
                    if (currentTransStudy) currentTransStudy.reportText = txt.val();
                });
                dropdown.append(item);
            });
        }
    } catch (e) { console.error("Error cargando plantillas:", e); }
}

// === PEDALERA PHILIPS LFH2330 / ACC2330 / SpeechMike (WebHID) ===
function hasTranscriptionAudioSource() {
    const audio = document.getElementById("audioDictado");
    return !!(
        audio &&
        audio.src &&
        audio.src !== "" &&
        audio.src !== window.location.href
    );
}

function isTranscriptionAudioListeningMode() {
    return !!currentTranscriptionChain && hasTranscriptionAudioSource();
}

function pauseTranscriptionAudio() {
    const audio = document.getElementById("audioDictado");
    if (!audio || !audio.src) {
        return false;
    }
    audio.pause();
    $("#iconPlayPause").removeClass("bi-pause-fill").addClass("bi-play-fill");
    return true;
}

function executeTranscriptionSpeechMikeAudioAction(action) {
    switch (action) {
        case "seek_back":
            skipAudio(-5);
            return true;
        case "seek_back_long":
            skipAudio(-15);
            return true;
        case "seek_forward":
            skipAudio(5);
            return true;
        case "seek_forward_long":
            skipAudio(15);
            return true;
        case "toggle_play":
            togglePlayPause();
            return true;
        case "pause":
            return pauseTranscriptionAudio();
        default:
            return false;
    }
}

function setupTranscriptionSpeechMikeHooks() {
    window.isAudioPreviewListeningMode = isTranscriptionAudioListeningMode;
    window.executeSpeechMikeAudioAction = executeTranscriptionSpeechMikeAudioAction;
}

// === ATAJOS DE TECLADO (pedaleras suelen emular F1–F4) ===
function setupKeyboardShortcuts() {
    $(document).on('keydown', function (e) {
        if (!currentTranscriptionChain) return;
        if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') && e.key !== 'F4') {
            return;
        }

        switch (e.key) {
            case 'F4':
                e.preventDefault();
                togglePlayPause();
                break;
            case 'F2':
                e.preventDefault();
                skipAudio(-5);
                break;
            case 'F3':
                e.preventDefault();
                skipAudio(5);
                break;
            case 'F1':
                e.preventDefault();
                skipAudio(-15);
                break;
            default:
                break;
        }
    });
}