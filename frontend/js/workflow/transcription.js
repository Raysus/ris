/* =========================================
   MÓDULO DE TRANSCRIPCIÓN (transcription.js)
   ========================================= */

let currentTranscriptionData = [];
let currentTranscriptionChain = null;
let currentTransStudy = null;
let colorInformeGlobal = "#333333";

// Declaración global para evitar el Uncaught ReferenceError
let autoSaveIntervalTrans = null;

function initTranscription() {
    cargarAjustesVisuales();
    cargarListaTranscripcion();
    cargarPlantillasTranscripcion();
    setupKeyboardShortcuts();
    setupAudioListeners();

    setInterval(() => {
        if (currentTransStudy || currentTranscriptionChain || $("#textoTranscripcion").is(":focus")) {
            console.log("🔄 Refresco de transcripción omitido: Transcriptora trabajando en un registro.");
            return;
        }
        cargarListaTranscripcion();
    }, 30000);
}

async function cargarAjustesVisuales() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    try {
        const response = await fetch(`${API_URL}/settings`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
        });
        const data = await response.json();

        if (response.ok && data.success && data.data && data.data.settings && data.data.settings.colorInforme) {
            colorInformeGlobal = data.data.settings.colorInforme;
            $("#textoTranscripcion").css("color", colorInformeGlobal);
        }
    } catch (e) { console.error("Error cargando ajustes visuales:", e); }
}

async function cargarListaTranscripcion() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    try {
        const response = await fetch(`${API_URL}/transcription/appointments`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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
    contenedor.empty();

    if (currentTranscriptionData.length === 0) {
        contenedor.append('<div class="p-4 text-center text-muted small"><i class="bi bi-check2-circle fs-3 d-block mb-2 text-success"></i>Bandeja al día. No hay dictados.</div>');
        $("#contadorAudios").text(0);
        return;
    }

    currentTranscriptionData.forEach(app => {
        const selectedClass = (currentTranscriptionChain && currentTranscriptionChain.id === app.id) ? 'active bg-primary text-white' : '';
        const badgeAudio = app.hasAudio ? '<span class="badge bg-success small"><i class="bi bi-mic-fill"></i> Audio</span>' : '<span class="badge bg-secondary small">Sin Audio</span>';

        // 💡 LÓGICA VISUAL: Identificar si viene devuelto de validación o es flujo normal
        let badgeEstado = '';
        let claseBorde = 'border-start border-4 border-primary';

        if (app.needsReview && app.returnReason !== '') {
            claseBorde = 'border-start border-4 border-danger bg-danger-subtle';
            badgeEstado = `
                <div class="mt-2 small text-danger fw-bold">
                    <i class="bi bi-exclamation-triangle-fill"></i> Devuelto: ${app.returnReason}
                </div>`;
        } else {
            badgeEstado = `<div class="mt-2 small text-success opacity-75"><i class="bi bi-check-circle"></i> Dictado Nuevo</div>`;
        }

        const nombreCompleto = `${app.patient.name} ${app.patient.lastName} ${app.patient.secondLastName || ''}`.trim();

        const item = `
            <button type="button" class="list-group-item list-group-item-action p-3 d-flex flex-column align-items-start gap-1 ${selectedClass} ${claseBorde}" onclick="abrirTranscripcion('${app.id}')">
                <div class="d-flex w-100 justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold font-monospace text-truncate" style="max-width: 150px;">${app.accessionNumber}</h6>
                    ${badgeAudio}
                </div>
                <strong class="m-0 text-truncate w-100" style="font-size: 0.95rem;">${nombreCompleto}</strong>
                <div class="d-flex w-100 justify-content-between align-items-center mt-1 opacity-75 small">
                    <span>RUT: ${app.patient.rut}</span>
                    <span>Edad: ${app.patient.age}</span>
                </div>
                ${badgeEstado}
            </button>
        `;
        contenedor.append(item);
    });
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
            <div><strong class="d-block">Devuelto por Validación Médica:</strong> ${app.returnReason}</div>
        </div>
    ` : '';

    const nombreCompleto = `${app.patient.name} ${app.patient.lastName} ${app.patient.secondLastName || ''}`.trim();

    $("#infoPacienteTranscripcion").html(`
        ${retornoAlerta}
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-1 fw-bold text-dark"><i class="bi bi-person-fill me-2 text-secondary"></i>${nombreCompleto}</h5>
                <span class="badge bg-dark font-monospace fs-6">${app.accessionNumber}</span>
                <span class="text-muted small ms-3"><b>RUT:</b> ${app.patient.rut}</span>
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
        btn.html(`<i class="bi bi-file-earmark-medical me-1"></i> ${study.exam} ${subExamenLabel}`);

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
}

function cargarEstudioTranscripcion(studyId) {
    if (!currentTranscriptionChain) return;

    const study = currentTranscriptionChain.studies.find(s => s.study_id == studyId);
    if (!study) return;

    currentTransStudy = study;

    const txt = $("#textoTranscripcion");
    txt.prop("disabled", false).val(study.reportText || "");

    if (autoSaveIntervalTrans) {
        clearInterval(autoSaveIntervalTrans);
    }
    iniciarAutoguardadoTrans();

    const audioEl = document.getElementById("audioDictado");

    if (study.audioUrl) {
        audioEl.src = study.audioUrl;
        audioEl.load();

        $("#btnPlayPause").prop("disabled", false).removeClass("btn-secondary").addClass("btn-primary");
        $("#iconPlayPause").removeClass("bi-pause-fill").addClass("bi-play-fill");
        $("#audioProgress").val(0).prop("disabled", false);
        $("#timeCurrent").text("0:00");
        $("#timeTotal").text("0:00");
        setAudioSpeed(1.0);
    } else {
        audioEl.src = "";
        $("#btnPlayPause").prop("disabled", true).removeClass("btn-primary").addClass("btn-secondary");
        $("#iconPlayPause").removeClass("bi-pause-fill").addClass("bi-play-fill");
        $("#audioProgress").val(0).prop("disabled", true);
        $("#timeCurrent").text("0:00");
        $("#timeTotal").text("0:00");
    }
}

// === ASINCRONISMO Y ESCUCHADORES DEL AUDIO ===
function setupAudioListeners() {
    const audio = document.getElementById("audioDictado");
    if (!audio) return;

    audio.addEventListener("timeupdate", () => {
        if (!audio.duration) return;
        const current = audio.currentTime;
        const total = audio.duration;

        $("#audioProgress").val((current / total) * 100);
        $("#timeCurrent").text(formatTime(current));
    });

    audio.addEventListener("loadedmetadata", () => {
        $("#timeTotal").text(formatTime(audio.duration));
    });

    audio.addEventListener("ended", () => {
        $("#iconPlayPause").removeClass("bi-pause-fill").addClass("bi-play-fill");
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
    audio.currentTime = Math.max(0, Math.min(audio.duration, audio.currentTime + seconds));
}

function seekAudio() {
    const audio = document.getElementById("audioDictado");
    if (!audio || !audio.duration) return;
    const porcentaje = $("#audioProgress").val();
    audio.currentTime = (porcentaje / 100) * audio.duration;
}

function setAudioSpeed(speed) {
    const audio = document.getElementById("audioDictado");
    if (!audio) return;
    audio.playbackRate = speed;
    $("#speedIndicator").text(speed + "x");
}

function formatTime(seconds) {
    if (isNaN(seconds)) return "0:00";
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m}:${s < 10 ? '0' : ''}${s}`;
}

// === CONTROL DE AUTOGUARDADO ===
function iniciarAutoguardadoTrans() {
    autoSaveIntervalTrans = setInterval(async () => {
        if (!currentTranscriptionChain || !currentTransStudy) return;

        const textActual = $("#textoTranscripcion").val();

        try {
            const token = localStorage.getItem('ris_token');
            const labId = localStorage.getItem('ris_lab_id');

            const payload = {
                reports: [{
                    id: currentTransStudy.study_id,
                    text: textActual
                }]
            };

            $("#autoSaveIndTrans").fadeIn().html('<i class="bi bi-arrow-repeat text-primary spinning"></i> Guardando...');

            const response = await fetch(`${API_URL}/transcription/appointments/${currentTranscriptionChain.id}/draft`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
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

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const btn = $("#btnEnviarValidacion");

    // Construcción exacta del payload: 
    // El backend espera un array llamado 'reports'
    const payload = {
        reports: currentTranscriptionChain.studies.map(s => ({
            id: s.study_id,
            text: s.reportText || '' // Asegura que no sea null
        }))
    };

    try {
        btn.prop('disabled', true).html('Enviando...');

        const response = await fetch(`${API_URL}/transcription/appointments/${currentTranscriptionChain.id}/validate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`,
                'X-Lab-Id': labId
            },
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

    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    const btn = $("#btnDevolverAudio");

    try {
        btn.prop('disabled', true).html('Devolviendo...');

        const response = await fetch(`${API_URL}/transcription/appointments/${currentTranscriptionChain.id}/return`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId },
            body: JSON.stringify({ reason: motivo })
        });

        if (response.ok) {
            if (typeof showToast === 'function') showToast("Audio devuelto a la bandeja del Radiólogo.", "warning");
            limpiarPantallaTranscripcion();
            cargarListaTranscripcion();
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
    $("#btnDevolverAudio").prop("disabled", true);
    $("#btnEnviarValidacion").prop("disabled", true);
    $("#btnPlantillaTrans").prop("disabled", true);
    $("#audioProgress").val(0).prop("disabled", true);
    $("#timeCurrent").text("0:00");
    $("#timeTotal").text("0:00");
}

// === PLANTILLAS DE TRANSCRIPCIÓN ===
async function cargarPlantillasTranscripcion() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    try {
        const response = await fetch(`${API_URL}/templates`, {
            headers: { 'Authorization': `Bearer ${token}`, 'X-Lab-Id': labId }
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

// === ATAJOS DE TECLADO MUNDIALES (F4 y F2) ===
function setupKeyboardShortcuts() {
    $(document).on('keydown', function (e) {
        if (currentTranscriptionChain) {
            if (e.key === 'F4') {
                e.preventDefault();
                togglePlayPause();
            }
            if (e.key === 'F2') {
                e.preventDefault();
                skipAudio(-5);
            }
        }
    });
}